<?php

namespace AzureBlob\Support;

use AzureBlob\Exceptions\AzureBlobException;

/**
 * Envio de block blobs.
 *
 * Conteúdo pequeno vai num único `Put Blob`. Acima de `block_size` o envio é
 * fatiado em `Put Block` + `Put Block List`, o que mantém o uso de memória
 * constante e contorna o teto de 256 MiB do PUT simples.
 */
final class Uploader
{
    /** Nº máximo de blocos por blob, imposto pelo Azure. */
    private const MAX_BLOCKS = 50000;

    public function __construct(private RestClient $client) {}

    /**
     * @param  string|resource  $contents
     * @param  array<string,mixed>  $options
     * @return string URL do blob enviado.
     *
     * @throws AzureBlobException
     */
    public function upload(string $path, $contents, array $options = []): string
    {
        if (is_resource($contents)) {
            return $this->uploadStream($path, $contents, $options);
        }

        $contents = (string) $contents;

        return strlen($contents) > $this->client->config()->blockSize
            ? $this->uploadInBlocks($path, $this->stringStream($contents), $options)
            : $this->uploadSingle($path, $contents, $options);
    }

    /**
     * @param  resource  $stream
     * @param  array<string,mixed>  $options
     */
    private function uploadStream(string $path, $stream, array $options): string
    {
        $blockSize = $this->client->config()->blockSize;

        // Lê um bloco a mais que o limite para decidir entre PUT simples e
        // blocos sem precisar do tamanho total, que um stream pode não expor.
        $head = $this->read($stream, $blockSize + 1);

        if (strlen($head) <= $blockSize) {
            return $this->uploadSingle($path, $head, $options);
        }

        return $this->uploadInBlocks($path, $stream, $options, $head);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function uploadSingle(string $path, string $contents, array $options): string
    {
        $headers = array_merge(
            ['x-ms-blob-type' => 'BlockBlob', 'Content-Type' => self::contentType($options, $path)],
            self::contentHeaders($options),
            self::metadataHeaders($options),
            self::conditionalHeaders($options),
        );

        $this->client->request('PUT', $path, [], $headers, $contents);

        return $this->client->config()->accountUrl.'/'.Path::encode($path);
    }

    /**
     * Envia em blocos e commita a lista.
     *
     * @param  resource  $stream
     * @param  array<string,mixed>  $options
     */
    private function uploadInBlocks(string $path, $stream, array $options, string $head = ''): string
    {
        $blockIds = $this->stageBlocks($path, $stream, $head);

        $this->commitBlocks($path, $blockIds, $options);

        return $this->client->config()->accountUrl.'/'.Path::encode($path);
    }

    /**
     * Envia cada pedaço como um bloco não commitado.
     *
     * @param  resource  $stream
     * @param  string  $head  Primeiro pedaço já lido pelo caminho de stream.
     * @return array<int,string> Ids dos blocos, na ordem.
     */
    private function stageBlocks(string $path, $stream, string $head): array
    {
        $blockSize = $this->client->config()->blockSize;
        $blockIds = [];
        $index = 0;

        // $head só vem preenchido pelo caminho de stream, que já leu o começo
        // para decidir entre PUT simples e blocos. Vindo de uma string, o
        // primeiro bloco ainda precisa ser lido aqui.
        $chunk = $head !== '' ? $head : $this->read($stream, $blockSize);

        while ($chunk !== '') {
            if (count($blockIds) >= self::MAX_BLOCKS) {
                throw new AzureBlobException(
                    sprintf(
                        'azure-blob: "%s" excede %d blocos. Aumente "block_size" na conexão.',
                        $path,
                        self::MAX_BLOCKS
                    ),
                    0,
                    null,
                    ['blob' => $path, 'block_size' => $blockSize]
                );
            }

            $blockId = self::blockId($index);

            $this->client->request(
                'PUT',
                $path,
                ['comp' => 'block', 'blockid' => $blockId],
                ['Content-Type' => 'application/octet-stream'],
                $chunk,
            );

            $blockIds[] = $blockId;
            $index++;
            $chunk = $this->read($stream, $blockSize);
        }

        return $blockIds;
    }

    /**
     * Commita a lista de blocos — é este passo que faz o blob existir.
     *
     * @param  array<int,string>  $blockIds
     * @param  array<string,mixed>  $options
     */
    private function commitBlocks(string $path, array $blockIds, array $options): void
    {
        $headers = array_merge(
            [
                'Content-Type' => 'application/xml',
                'x-ms-blob-content-type' => self::contentType($options, $path),
            ],
            self::blobContentHeaders($options),
            self::metadataHeaders($options),
            self::conditionalHeaders($options),
        );

        $this->client->request('PUT', $path, ['comp' => 'blocklist'], $headers, Xml::blockList($blockIds));
    }

    /**
     * Ids de bloco precisam ter todos o mesmo comprimento antes do base64 —
     * o Azure rejeita a lista com InvalidBlockList caso contrário.
     */
    private static function blockId(int $index): string
    {
        return base64_encode(sprintf('block-%08d', $index));
    }

    /**
     * `fread` pode devolver menos bytes que o pedido antes do fim do stream
     * (sockets, pipes), então o laço insiste até completar o bloco ou acabar.
     *
     * @param  resource  $stream
     */
    private function read($stream, int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length && ! feof($stream)) {
            $chunk = fread($stream, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /** @return resource */
    private function stringStream(string $contents)
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new AzureBlobException('azure-blob: não foi possível abrir um stream temporário para o upload.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private static function contentType(array $options, string $path): string
    {
        $type = $options['content_type'] ?? $options['contentType'] ?? null;

        return is_string($type) && trim($type) !== '' ? trim($type) : MimeType::guess($path);
    }

    /**
     * Cabeçalhos de conteúdo do `Put Blob`.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,string>
     */
    private static function contentHeaders(array $options): array
    {
        return self::pick($options, [
            'cache_control' => 'Cache-Control',
            'content_disposition' => 'Content-Disposition',
            'content_encoding' => 'Content-Encoding',
            'content_language' => 'Content-Language',
        ]);
    }

    /**
     * Os mesmos cabeçalhos, na forma exigida pelo `Put Block List` — lá eles
     * descrevem o blob final, não o corpo XML da requisição.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,string>
     */
    private static function blobContentHeaders(array $options): array
    {
        return self::pick($options, [
            'cache_control' => 'x-ms-blob-cache-control',
            'content_disposition' => 'x-ms-blob-content-disposition',
            'content_encoding' => 'x-ms-blob-content-encoding',
            'content_language' => 'x-ms-blob-content-language',
        ]);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,string>
     */
    private static function metadataHeaders(array $options): array
    {
        $metadata = $options['metadata'] ?? [];
        $headers = [];

        if (! is_array($metadata)) {
            return $headers;
        }

        foreach ($metadata as $name => $value) {
            // Nomes de metadado viram identificadores C# no Azure: só letras,
            // dígitos e underscore, sem começar com dígito.
            $name = (string) preg_replace('/[^A-Za-z0-9_]/', '_', (string) $name);

            if ($name !== '' && ! ctype_digit($name[0])) {
                $headers['x-ms-meta-'.$name] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * `overwrite => false` vira `If-None-Match: *`, que faz o Azure devolver
     * 409 em vez de sobrescrever um blob existente.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,string>
     */
    private static function conditionalHeaders(array $options): array
    {
        $overwrite = $options['overwrite'] ?? true;

        return $overwrite === false ? ['If-None-Match' => '*'] : [];
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,string>  $map
     * @return array<string,string>
     */
    private static function pick(array $options, array $map): array
    {
        $headers = [];

        foreach ($map as $option => $header) {
            $value = $options[$option] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $headers[$header] = trim($value);
            }
        }

        return $headers;
    }
}
