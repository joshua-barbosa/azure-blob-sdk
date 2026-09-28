<?php

namespace AzureBlob;

use AzureBlob\Exceptions\AzureBlobException;
use AzureBlob\Exceptions\BlobTooLargeException;
use AzureBlob\Exceptions\ReadOnlyException;
use AzureBlob\Results\BlobContent;
use AzureBlob\Results\BlobItem;
use AzureBlob\Results\BlobList;
use AzureBlob\Results\BlobProperties;
use AzureBlob\Support\Config;
use AzureBlob\Support\MimeType;
use AzureBlob\Support\Path;
use AzureBlob\Support\RestClient;
use AzureBlob\Support\SasBuilder;
use AzureBlob\Support\Uploader;
use DateTimeInterface;
use Generator;
use GuzzleHttp\Psr7\StreamWrapper;
use JsonSerializable;

/**
 * Cliente de uma conexão do Azure Blob Storage.
 *
 * Cada instância aponta para um container; `container()` devolve uma cópia
 * apontando para outro, sem alterar a original.
 *
 *     $blob = app(BlobManager::class)->connection('apostilas');
 *
 *     $blob->list('2024/');
 *     $blob->download('2024/apostila.pdf')->saveTo('/tmp/a.pdf');
 *     $blob->upload('2024/nova.pdf', $conteudo);
 *     $blob->container('backup')->upload('copia.pdf', $conteudo);
 */
class BlobClient
{
    /** Página máxima aceita pelo Azure em `List Blobs`. */
    public const MAX_PAGE = 5000;

    private ?Uploader $uploader = null;

    public function __construct(private RestClient $client) {}

    public function config(): Config
    {
        return $this->client->config();
    }

    /** Nome do container atual. */
    public function containerName(): string
    {
        return $this->config()->container;
    }

    /** Cópia apontando para outro container. */
    public function container(?string $container): self
    {
        $config = $this->config()->withContainer($container);

        if ($config === $this->config()) {
            return $this;
        }

        return new self($this->client->withConfig($config));
    }

    /** Cópia com a trava de escrita ligada, para trechos que só devem ler. */
    public function readOnly(): self
    {
        $config = $this->config()->readOnly();

        return $config === $this->config() ? $this : new self($this->client->withConfig($config));
    }

    /** Descrição legível da conexão (conta, container, modo de auth). */
    public function info(): string
    {
        return $this->config()->describe();
    }

    public function isReadOnly(): bool
    {
        return $this->config()->readonly;
    }

    // ========================================================================
    // Leitura
    // ========================================================================

    /**
     * Lista blobs do container.
     *
     * @param  string|null  $prefix  Filtra por início do nome (ex.: `pasta/subpasta/`).
     * @param  int  $maxResults  Teto de itens nesta página.
     * @param  array<string,mixed>  $options  `marker`, `delimiter`, `include` (ex.: `metadata`).
     *
     * @throws AzureBlobException
     */
    public function list(?string $prefix = null, int $maxResults = 100, array $options = []): BlobList
    {
        $query = [
            'restype' => 'container',
            'comp' => 'list',
            'maxresults' => (string) max(1, min($maxResults, self::MAX_PAGE)),
        ];

        if ($prefix !== null && Path::normalize($prefix) !== '') {
            $query['prefix'] = Path::normalize($prefix);
        }

        foreach (['marker', 'delimiter', 'include'] as $option) {
            $value = $options[$option] ?? null;

            if (is_string($value) && $value !== '') {
                $query[$option] = $value;
            }
        }

        $response = $this->client->request('GET', $this->containerName(), $query);

        return BlobList::fromXml(
            (string) $response->body(),
            $this->config()->containerUrl(),
            $this->containerName(),
        );
    }

    /**
     * Percorre todas as páginas, seguindo o `NextMarker`.
     *
     * Devolve um Generator para que listar 100.000 blobs não carregue todos na
     * memória de uma vez.
     *
     * @param  array<string,mixed>  $options
     * @return Generator<int,BlobItem>
     *
     * @throws AzureBlobException
     */
    public function listAll(?string $prefix = null, array $options = []): Generator
    {
        $marker = null;

        do {
            $page = $this->list($prefix, self::MAX_PAGE, array_merge($options, array_filter(['marker' => $marker])));

            foreach ($page->items as $item) {
                yield $item;
            }

            $marker = $page->nextMarker;
        } while ($marker !== null);
    }

    /**
     * Listagem rasa de um "diretório": usa delimitador, então subpastas voltam
     * como `BlobPrefix` em vez de despejar a árvore inteira.
     *
     * @throws AzureBlobException
     */
    public function directory(string $path = '', int $maxResults = self::MAX_PAGE): BlobList
    {
        return $this->list(Path::directoryPrefix($path), $maxResults, ['delimiter' => '/']);
    }

    /**
     * Nomes dos blobs sob o prefixo, percorrendo todas as páginas.
     *
     * @param  int|null  $max  Teto de nomes; `null` traz todos.
     * @return array<int,string>
     *
     * @throws AzureBlobException
     */
    public function listNames(?string $prefix = null, ?int $max = null): array
    {
        $names = [];

        foreach ($this->listAll($prefix) as $item) {
            if ($max !== null && count($names) >= $max) {
                break;
            }

            $names[] = $item->name;
        }

        return $names;
    }

    /**
     * Arquivos de uma "pasta". Sem `$recursive`, só os do nível atual.
     *
     * @return array<int,string>
     *
     * @throws AzureBlobException
     */
    public function files(string $path = '', bool $recursive = false): array
    {
        if ($recursive) {
            return $this->listNames(Path::directoryPrefix($path));
        }

        $names = [];

        foreach ($this->directoryPages($path) as $page) {
            array_push($names, ...$page->names());
        }

        return $names;
    }

    /**
     * Subpastas imediatas de uma "pasta", sem barra final.
     *
     * @return array<int,string>
     *
     * @throws AzureBlobException
     */
    public function directories(string $path = ''): array
    {
        $names = [];

        foreach ($this->directoryPages($path) as $page) {
            foreach ($page->directories as $directory) {
                $names[] = $directory->name;
            }
        }

        return $names;
    }

    /**
     * Baixa o conteúdo do blob para a memória.
     *
     * @throws AzureBlobException
     */
    public function download(string $blob, array $options = []): BlobContent
    {
        $blob = Path::normalize($blob);
        $properties = $this->properties($blob);
        $max = (int) ($options['max_size'] ?? $this->config()->maxDownloadSize);

        if ($max > 0 && $properties->size > $max) {
            throw BlobTooLargeException::make($blob, $properties->size, $max);
        }

        $response = $this->client->request('GET', $this->path($blob));

        return new BlobContent(
            contents: (string) $response->body(),
            name: $blob,
            contentType: $properties->contentType,
            size: $properties->size,
            properties: $properties,
        );
    }

    /**
     * Baixa e decodifica um blob JSON.
     *
     * @return array<mixed>|mixed
     *
     * @throws AzureBlobException
     */
    public function downloadJson(string $blob, bool $associative = true)
    {
        // O limite de tamanho não se aplica: um JSON que o SDK vai decodificar
        // na memória de qualquer forma já é limitado pelo memory_limit do PHP.
        $response = $this->client->request('GET', $this->path(Path::normalize($blob)));

        return (new BlobContent(
            contents: (string) $response->body(),
            name: Path::normalize($blob),
            contentType: $response->header('Content-Type') ?: null,
        ))->json($associative);
    }

    /**
     * Conteúdo do blob como texto, sem checagem de tamanho.
     *
     * Em PHP é o mesmo que `get()` — strings são bytes —, e existe pela
     * paridade com o SDK Node e com a função `azure_download_text` original.
     *
     * @throws AzureBlobException
     */
    public function downloadText(string $blob): string
    {
        return $this->get($blob);
    }

    /**
     * Bytes crus do blob, sem checagem de tamanho.
     *
     * @throws AzureBlobException
     */
    public function get(string $blob): string
    {
        return (string) $this->client->request('GET', $this->path($blob))->body();
    }

    /**
     * Stream de leitura do blob, sem carregar tudo na memória.
     *
     * @return resource
     *
     * @throws AzureBlobException
     */
    public function stream(string $blob)
    {
        $response = $this->client->request('GET', $this->path($blob), options: ['stream' => true]);

        return StreamWrapper::getResource($response->toPsrResponse()->getBody());
    }

    /**
     * Baixa direto para um arquivo local, em blocos.
     *
     * Cria a pasta de destino quando falta e grava num arquivo temporário ao
     * lado do destino, renomeando só no fim: um 404 ou uma queda no meio não
     * destroem uma cópia anterior do arquivo.
     *
     * @return int Bytes gravados.
     *
     * @throws AzureBlobException
     */
    public function downloadTo(string $blob, string $path): int
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new AzureBlobException(
                sprintf('azure-blob: não foi possível criar a pasta "%s".', $directory),
                0,
                null,
                ['blob' => $blob, 'path' => $path]
            );
        }

        $partial = sprintf('%s.%d.%s.part', $path, getmypid(), bin2hex(random_bytes(4)));
        $destination = @fopen($partial, 'x');

        if ($destination === false) {
            throw new AzureBlobException(
                sprintf('azure-blob: não foi possível abrir "%s" para escrita.', $path),
                0,
                null,
                ['blob' => $blob, 'path' => $path]
            );
        }

        try {
            $source = $this->stream($blob);

            try {
                $written = stream_copy_to_stream($source, $destination);
            } finally {
                fclose($source);
            }

            fclose($destination);
            $destination = null;

            if ($written === false || ! @rename($partial, $path)) {
                throw new AzureBlobException(
                    sprintf('azure-blob: não foi possível gravar "%s".', $path),
                    0,
                    null,
                    ['blob' => $blob, 'path' => $path]
                );
            }

            return (int) $written;
        } finally {
            if (is_resource($destination)) {
                fclose($destination);
            }

            if (is_file($partial)) {
                @unlink($partial);
            }
        }
    }

    /**
     * Propriedades e metadados do blob.
     *
     * @throws AzureBlobException
     */
    public function properties(string $blob): BlobProperties
    {
        $blob = Path::normalize($blob);
        $response = $this->client->request('HEAD', $this->path($blob));

        return BlobProperties::fromHeaders(
            $response->headers(),
            $blob,
            $this->containerName(),
            $this->url($blob),
        );
    }

    public function exists(string $blob): bool
    {
        $response = $this->client->request('HEAD', $this->path($blob), allow: [404]);

        return $response->status() !== 404;
    }

    public function missing(string $blob): bool
    {
        return ! $this->exists($blob);
    }

    /** @throws AzureBlobException */
    public function size(string $blob): int
    {
        return $this->properties($blob)->size;
    }

    /** @throws AzureBlobException */
    public function lastModified(string $blob): ?DateTimeInterface
    {
        return $this->properties($blob)->lastModified;
    }

    /** @throws AzureBlobException */
    public function mimeType(string $blob): ?string
    {
        return $this->properties($blob)->contentType;
    }

    /** URL pública do blob. Só abre sem token se o container for público. */
    public function url(string $blob): string
    {
        return $this->config()->blobUrl($blob);
    }

    /**
     * URL assinada, válida por tempo limitado.
     *
     * Com `sas_url` configurada o token do container é reaproveitado e
     * `$expiry`/`$permissions` são ignorados — expiração e permissões já vêm
     * fixadas no token. Nos modos com chave da conta um SAS novo é assinado.
     *
     * @param  DateTimeInterface|int  $expiry  Instante de expiração, ou horas a partir de agora.
     * @param  string  $permissions  r=read, a=add, c=create, w=write, d=delete, l=list.
     */
    public function temporaryUrl(string $blob, $expiry = 1, string $permissions = 'r'): string
    {
        $blob = Path::normalize($blob);

        if ($this->config()->usesSasToken()) {
            return $this->url($blob).'?'.$this->config()->sasToken;
        }

        $token = $this->client->sas()->forBlob($this->containerName(), $blob, $expiry, $permissions);

        return $this->url($blob).'?'.$token;
    }

    /** Alias de `temporaryUrl()`, com o nome usado pela ferramenta MCP. */
    public function sasUrl(string $blob, $expiry = 1, string $permissions = 'r'): string
    {
        return $this->temporaryUrl($blob, $expiry, $permissions);
    }

    /**
     * URL assinada do container inteiro (útil para listagem delegada).
     *
     * @param  DateTimeInterface|int  $expiry
     */
    public function temporaryContainerUrl($expiry = 1, string $permissions = 'rl'): string
    {
        if ($this->config()->usesSasToken()) {
            return $this->config()->containerUrl().'?'.$this->config()->sasToken;
        }

        $token = $this->client->sas()->forContainer($this->containerName(), $expiry, $permissions);

        return $this->config()->containerUrl().'?'.$token;
    }

    // ========================================================================
    // Container
    // ========================================================================

    /**
     * True quando o container da conexão existe.
     *
     * @throws AzureBlobException
     */
    public function containerExists(): bool
    {
        // Um SAS de container não autoriza Get Container Properties (403), mas
        // autoriza listar: uma listagem de 1 item responde 404 sem o container.
        $response = $this->config()->usesSasToken()
            ? $this->client->request('GET', $this->containerName(), ['restype' => 'container', 'comp' => 'list', 'maxresults' => '1'], allow: [404])
            : $this->client->request('HEAD', $this->containerName(), ['restype' => 'container'], allow: [404]);

        return $response->status() !== 404;
    }

    /**
     * Cria o container quando ele não existe.
     *
     * Criar container exige a chave da conta ou um SAS de conta: um SAS de
     * container (o modo SAS URL típico) não tem essa permissão.
     *
     * @return bool `true` se o container foi criado, `false` se já existia.
     *
     * @throws AzureBlobException
     */
    public function ensureContainer(): bool
    {
        $this->guardWrites('ensureContainer');

        $response = $this->client->request('PUT', $this->containerName(), ['restype' => 'container'], allow: [409]);

        if ($response->status() !== 409) {
            return true;
        }

        $code = $response->header('x-ms-error-code');

        // 409 também chega para ContainerBeingDeleted: aí o container não
        // existe e não vai existir tão cedo, o que não é "já existia".
        if ($code !== '' && $code !== 'ContainerAlreadyExists') {
            throw new AzureBlobException(
                sprintf('azure-blob: não foi possível criar o container "%s" (%s).', $this->containerName(), $code),
                409,
                null,
                ['container' => $this->containerName(), 'status' => 409, 'error_code' => $code]
            );
        }

        return false;
    }

    // ========================================================================
    // Escrita
    // ========================================================================

    /**
     * Envia conteúdo para um blob.
     *
     * @param  string|resource  $contents
     * @param  array<string,mixed>  $options  `content_type`, `overwrite`, `metadata`,
     *                                        `cache_control`, `content_disposition`, `content_encoding`.
     * @return string URL do blob.
     *
     * @throws AzureBlobException
     */
    public function upload(string $blob, $contents, array $options = []): string
    {
        $this->guardWrites('upload');

        return $this->uploader()->upload($this->path($blob), $contents, $options);
    }

    /**
     * Serializa e envia dados como JSON.
     *
     * @param  array<mixed>|JsonSerializable  $data
     *
     * @throws AzureBlobException
     */
    public function uploadJson(string $blob, $data, array $options = []): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($json === false) {
            throw new AzureBlobException(
                sprintf('azure-blob: não foi possível serializar os dados de "%s": %s.', $blob, json_last_error_msg()),
                0,
                null,
                ['blob' => $blob]
            );
        }

        return $this->upload($blob, $json, array_merge(['content_type' => 'application/json'], $options));
    }

    /**
     * Envia um arquivo local, em blocos quando necessário.
     *
     * @throws AzureBlobException
     */
    public function uploadFile(string $blob, string $path, array $options = []): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new AzureBlobException(
                sprintf('azure-blob: arquivo "%s" não existe ou não pode ser lido.', $path),
                0,
                null,
                ['blob' => $blob, 'path' => $path]
            );
        }

        $stream = fopen($path, 'r');

        if ($stream === false) {
            throw new AzureBlobException(
                sprintf('azure-blob: não foi possível abrir "%s" para leitura.', $path),
                0,
                null,
                ['blob' => $blob, 'path' => $path]
            );
        }

        try {
            // O tipo vem do nome do blob; o do arquivo local só entra quando o
            // blob não tem extensão reconhecida (ex.: upload de /tmp/phpA1b2C3).
            $type = MimeType::guess($blob);

            return $this->upload($blob, $stream, array_merge(
                ['content_type' => $type !== MimeType::DEFAULT ? $type : MimeType::guess($path)],
                $options,
            ));
        } finally {
            fclose($stream);
        }
    }

    /**
     * Substitui os metadados do blob.
     *
     * @param  array<string,string>  $metadata
     *
     * @throws AzureBlobException
     */
    public function setMetadata(string $blob, array $metadata): bool
    {
        $this->guardWrites('setMetadata');

        $headers = [];

        foreach ($metadata as $name => $value) {
            $name = (string) preg_replace('/[^A-Za-z0-9_]/', '_', (string) $name);

            if ($name !== '' && ! ctype_digit($name[0])) {
                $headers['x-ms-meta-'.$name] = (string) $value;
            }
        }

        $this->client->request('PUT', $this->path($blob), ['comp' => 'metadata'], $headers);

        return true;
    }

    /**
     * Remove um blob.
     *
     * @return bool `false` quando o blob já não existia.
     *
     * @throws AzureBlobException
     */
    public function delete(string $blob): bool
    {
        $this->guardWrites('delete');

        $response = $this->client->request(
            'DELETE',
            $this->path($blob),
            headers: ['x-ms-delete-snapshots' => 'include'],
            allow: [404],
        );

        return $response->status() !== 404;
    }

    /**
     * Remove todos os blobs sob um prefixo.
     *
     * @return int Quantidade removida.
     *
     * @throws AzureBlobException
     */
    public function deleteDirectory(string $prefix): int
    {
        $this->guardWrites('deleteDirectory');

        $directory = Path::directoryPrefix($prefix);

        // Um prefixo vazio apagaria o container inteiro — raramente é a intenção.
        if ($directory === '') {
            throw new AzureBlobException(
                'azure-blob: deleteDirectory() exige um prefixo; para esvaziar o container, apague blob a blob.',
                0,
                null,
                ['container' => $this->containerName()]
            );
        }

        $removed = 0;

        foreach ($this->listAll($directory) as $item) {
            // O nome vem do próprio Azure: vai sem normalização, ou blobs como
            // "dir//a" ou "dir/a " seriam procurados com outro nome.
            $response = $this->client->request(
                'DELETE',
                $this->containerName().'/'.$item->name,
                headers: ['x-ms-delete-snapshots' => 'include'],
                allow: [404],
            );

            $removed += $response->status() === 404 ? 0 : 1;
        }

        return $removed;
    }

    /**
     * Copia um blob, possivelmente entre containers.
     *
     * @param  array<string,mixed>  $options  `source_container`, `destination_container`, `wait`.
     * @return string URL do destino.
     *
     * @throws AzureBlobException
     */
    public function copy(string $source, string $destination, array $options = []): string
    {
        $this->guardWrites('copy');

        $from = $this->container($options['source_container'] ?? null);
        $to = $this->container($options['destination_container'] ?? null);

        $response = $to->client()->request(
            'PUT',
            $to->path($destination),
            headers: ['x-ms-copy-source' => $from->copySourceUrl($source)],
        );

        $status = $response->header('x-ms-copy-status');

        if ($status === 'failed' || $status === 'aborted') {
            throw self::copyFailed($destination, $to->containerName(), $status, $response->header('x-ms-copy-status-description'));
        }

        if (($options['wait'] ?? false) === true && $status === 'pending') {
            $to->waitForCopy($destination, (int) ($options['timeout'] ?? 30));
        }

        return $to->url($destination);
    }

    /**
     * Copia e apaga a origem.
     *
     * @param  array<string,mixed>  $options
     *
     * @throws AzureBlobException
     */
    public function move(string $source, string $destination, array $options = []): string
    {
        $from = $this->container($options['source_container'] ?? null);
        $to = $this->container($options['destination_container'] ?? null);

        // Copiar sobre si mesmo e depois apagar a origem apagaria o blob.
        if ($from->path($source) === $to->path($destination)) {
            throw new AzureBlobException(
                sprintf('azure-blob: origem e destino de move() são o mesmo blob ("%s").', Path::normalize($source)),
                0,
                null,
                ['blob' => Path::normalize($source), 'container' => $from->containerName()]
            );
        }

        // A cópia entre containers é assíncrona no Azure; sem esperar, o delete
        // abaixo poderia apagar a origem antes de ela ser lida por completo.
        $url = $this->copy($source, $destination, array_merge($options, ['wait' => true]));

        $this->container($options['source_container'] ?? null)->delete($source);

        return $url;
    }

    // ========================================================================
    // Internos
    // ========================================================================

    /** Caminho completo `container/blob` usado pelo RestClient. */
    public function path(string $blob): string
    {
        return $this->containerName().'/'.Path::normalize($blob);
    }

    public function client(): RestClient
    {
        return $this->client;
    }

    /**
     * URL que o serviço do Azure usará para ler a origem de uma cópia.
     *
     * Precisa carregar credencial própria: o serviço lê a origem por conta
     * dele, sem os cabeçalhos da nossa requisição.
     */
    private function copySourceUrl(string $blob): string
    {
        if ($this->config()->usesSasToken()) {
            return $this->url($blob).'?'.$this->config()->sasToken;
        }

        return $this->url($blob).'?'.$this->client->sas()->forBlob(
            $this->containerName(),
            $blob,
            1,
            SasBuilder::normalizePermissions('r'),
        );
    }

    /** Aguarda uma cópia assíncrona terminar. */
    private function waitForCopy(string $blob, int $timeout): void
    {
        $deadline = time() + max(1, $timeout);

        do {
            $response = $this->client->request('HEAD', $this->path($blob));
            $status = $response->header('x-ms-copy-status');

            // Só "success" é sucesso: com "failed"/"aborted", um move() que
            // seguisse adiante apagaria a origem de uma cópia que não existe.
            if ($status === 'failed' || $status === 'aborted') {
                throw self::copyFailed($blob, $this->containerName(), $status, $response->header('x-ms-copy-status-description'));
            }

            if ($status !== 'pending') {
                return;
            }

            usleep(250000);
        } while (time() < $deadline);

        throw new AzureBlobException(
            sprintf('azure-blob: a cópia para "%s" não terminou em %d segundos.', $blob, $timeout),
            0,
            null,
            ['blob' => $blob, 'container' => $this->containerName(), 'timeout' => $timeout]
        );
    }

    /**
     * Páginas de uma listagem rasa, seguindo o `NextMarker`.
     *
     * @return Generator<int,BlobList>
     */
    private function directoryPages(string $path): Generator
    {
        $marker = null;

        do {
            $page = $this->list(
                Path::directoryPrefix($path),
                self::MAX_PAGE,
                array_filter(['delimiter' => '/', 'marker' => $marker]),
            );

            yield $page;

            $marker = $page->nextMarker;
        } while ($marker !== null);
    }

    private static function copyFailed(string $blob, string $container, string $status, string $description): AzureBlobException
    {
        return new AzureBlobException(
            sprintf(
                'azure-blob: a cópia para "%s" terminou com status "%s"%s.',
                $blob,
                $status,
                $description === '' ? '' : ' ('.$description.')'
            ),
            0,
            null,
            [
                'blob' => $blob,
                'container' => $container,
                'copy_status' => $status,
                'copy_status_description' => $description === '' ? null : $description,
            ]
        );
    }

    /** @throws ReadOnlyException */
    private function guardWrites(string $operation): void
    {
        if ($this->config()->readonly) {
            throw ReadOnlyException::for($this->config()->connection, $operation);
        }
    }

    private function uploader(): Uploader
    {
        return $this->uploader ??= new Uploader($this->client);
    }
}
