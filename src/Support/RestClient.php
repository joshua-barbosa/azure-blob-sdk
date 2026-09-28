<?php

namespace AzureBlob\Support;

use AzureBlob\Exceptions\AzureBlobException;
use AzureBlob\Exceptions\BlobNotFoundException;
use AzureBlob\Exceptions\ConfigurationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Executa as chamadas à REST API do Blob Storage.
 *
 * Concentra o que é comum a toda requisição — montagem da URL, autenticação
 * (SAS token na query ou assinatura Shared Key nos cabeçalhos), tradução de
 * erros — para que BlobClient trate só da semântica de cada operação.
 */
class RestClient
{
    private ?SharedKeySigner $signer = null;

    private ?LoggerInterface $logger = null;

    public function __construct(
        private Config $config,
        private ?Container $container = null,
    ) {}

    public function config(): Config
    {
        return $this->config;
    }

    /** Cópia apontando para outro container, sem refazer a resolução de config. */
    public function withConfig(Config $config): self
    {
        if ($config === $this->config) {
            return $this;
        }

        $clone = clone $this;
        $clone->config = $config;

        return $clone;
    }

    /**
     * Dispara uma requisição autenticada.
     *
     * @param  string  $path  Caminho relativo à conta (ex.: `container/pasta/a.txt`), já sem encoding.
     * @param  array<string,string|int>  $query
     * @param  array<string,string|int>  $headers
     * @param  array<string,mixed>  $options  Opções extras do Guzzle (ex.: `stream`).
     *
     * @throws AzureBlobException
     */
    public function send(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        ?string $body = null,
        array $options = [],
    ): Response {
        $method = strtoupper($method);
        $url = $this->url($path, $query);

        $headers = $this->authenticate($method, $url, $headers, $body);

        $contentType = (string) ($headers['Content-Type'] ?? 'application/octet-stream');

        // O Content-Type sai dos cabeçalhos avulsos quando há corpo: no Laravel
        // 8 o withBody() abaixo o define de novo e os dois valores acabam
        // concatenados ("text/plain,text/plain"), o que o Azure grava literal.
        // Ele já entrou na assinatura em authenticate(), então remover aqui não
        // afeta o Shared Key.
        if ($body !== null) {
            unset($headers['Content-Type']);
        }

        $request = $this->pending()->withHeaders($headers);

        if ($options !== []) {
            $request = $request->withOptions($options);
        }

        if ($body !== null) {
            // withBody() em vez de ->send($method, $url, ['body' => ...]) porque
            // preserva o Content-Type que entrou na assinatura.
            $request = $request->withBody($body, $contentType);
        }

        try {
            $response = $request->send($method, $url);
        } catch (Throwable $exception) {
            throw new AzureBlobException(
                sprintf('azure-blob: falha de comunicação com o Azure (%s).', $exception->getMessage()),
                0,
                $exception,
                Redactor::scrub(['connection' => $this->config->connection, 'method' => $method, 'url' => $url])
            );
        }

        return $response;
    }

    /**
     * Igual a `send()`, mas converte respostas de erro em exceção.
     *
     * @param  array<string,string|int>  $query
     * @param  array<string,string|int>  $headers
     * @param  array<string,mixed>  $options
     * @param  array<int,int>  $allow  Status extras aceitos sem lançar exceção.
     *
     * @throws AzureBlobException
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        ?string $body = null,
        array $options = [],
        array $allow = [],
    ): Response {
        $response = $this->send($method, $path, $query, $headers, $body, $options);

        if ($response->successful() || in_array($response->status(), $allow, true)) {
            return $response;
        }

        throw $this->exception($response, $method, $path);
    }

    /**
     * Traduz uma resposta de erro do Azure em exceção do SDK.
     */
    private function exception(Response $response, string $method, string $path): AzureBlobException
    {
        $status = $response->status();
        // O corpo de erro do Azure é XML; num download com `stream` ele pode
        // estar vazio, e aí o cabeçalho x-ms-error-code é a única pista.
        $error = Xml::error((string) $response->body());
        $code = $error['code'] ?? $response->header('x-ms-error-code') ?: null;

        // O caminho é "container/blob", mas operações de container (list) vêm
        // só com o container. Sem a checagem, strpos() devolve false, o (int)
        // vira 0 e o substr comeria a primeira letra do nome.
        $separator = strpos($path, '/');
        $blob = $separator === false ? '' : Path::normalize(substr($path, $separator + 1));

        if ($status === 404) {
            $exception = BlobNotFoundException::make($blob, $this->config->container, $response->toException());
        } else {
            $exception = new AzureBlobException(
                sprintf(
                    'azure-blob: %s %s falhou com HTTP %d%s.',
                    $method,
                    $path,
                    $status,
                    $code === null ? '' : ' ('.$code.')'
                ),
                $status,
                $response->toException(),
                [
                    'connection' => $this->config->connection,
                    'container' => $this->config->container,
                    'blob' => $blob,
                    'status' => $status,
                    'error_code' => $code,
                    'error_message' => $error['message'],
                ]
            );
        }

        $this->logger()->warning('azure-blob: requisição rejeitada pelo Azure.', Redactor::scrub([
            'connection' => $this->config->connection,
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'error_code' => $code,
            'error_message' => $error['message'],
        ]));

        return $exception;
    }

    /**
     * Monta a URL absoluta, anexando o SAS token quando ele é a credencial.
     *
     * O token é concatenado cru, sem passar por http_build_query: reencodar a
     * assinatura (`sig`) invalidaria o SAS.
     *
     * @param  array<string,string|int>  $query
     */
    public function url(string $path, array $query = []): string
    {
        // O caminho chega pronto do BlobClient: normalizado, ou — para nomes
        // vindos da listagem — exatamente como o Azure os devolveu.
        $url = rtrim($this->config->accountUrl, '/').'/'.Path::encodeRaw($path);

        $parts = [];

        if ($query !== []) {
            $parts[] = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        if ($this->config->usesSasToken() && $this->config->sasToken !== '') {
            $parts[] = $this->config->sasToken;
        }

        $parts = array_filter($parts, static fn (string $part): bool => $part !== '');

        return $parts === [] ? $url : $url.'?'.implode('&', $parts);
    }

    /**
     * Aplica o modo de autenticação da conexão aos cabeçalhos.
     *
     * @param  array<string,string|int>  $headers
     * @return array<string,string>
     */
    private function authenticate(string $method, string $url, array $headers, ?string $body): array
    {
        $headers['x-ms-version'] = $headers['x-ms-version'] ?? $this->config->apiVersion;
        $headers['x-ms-date'] = $headers['x-ms-date'] ?? gmdate('D, d M Y H:i:s \G\M\T');

        if ($body !== null && ! isset($headers['Content-Length'])) {
            $headers['Content-Length'] = (string) strlen($body);
        }

        // No modo SAS a credencial já viaja na query string montada por url().
        if ($this->config->usesSasToken()) {
            return array_map(static fn ($value): string => (string) $value, $headers);
        }

        return $this->signer()->sign($method, $url, $headers, $this->config->apiVersion);
    }

    private function signer(): SharedKeySigner
    {
        if ($this->signer !== null) {
            return $this->signer;
        }

        if (! $this->config->canSign()) {
            throw ConfigurationException::signingUnavailable($this->config->connection);
        }

        return $this->signer = new SharedKeySigner(
            (string) $this->config->accountName,
            (string) $this->config->accountKey,
        );
    }

    /** Gerador de SAS da conexão; exige a chave da conta. */
    public function sas(): SasBuilder
    {
        if (! $this->config->canSign()) {
            throw ConfigurationException::signingUnavailable($this->config->connection);
        }

        return new SasBuilder(
            (string) $this->config->accountName,
            (string) $this->config->accountKey,
            $this->config->apiVersion,
        );
    }

    public function logger(): LoggerInterface
    {
        return $this->logger ??= Logger::resolve($this->config, $this->container);
    }

    private function pending(): PendingRequest
    {
        return $this->http()
            ->withOptions(HttpOptions::fromConfig($this->config))
            ->timeout($this->config->timeout());
    }

    private function http(): HttpFactory
    {
        if ($this->container?->bound(HttpFactory::class)) {
            return $this->container->make(HttpFactory::class);
        }

        // O Laravel 8 não registra Http\Client\Factory no container — quem
        // guarda a instância viva é a própria facade. Resolver pelo container
        // criaria uma nova, sem os stubs de Http::fake(), e as requisições dos
        // testes de quem usa o pacote escapariam para a rede.
        if (Facade::getFacadeApplication() !== null) {
            $root = Http::getFacadeRoot();

            if ($root instanceof HttpFactory) {
                return $root;
            }
        }

        return new HttpFactory;
    }
}
