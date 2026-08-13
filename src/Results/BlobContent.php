<?php

namespace AzureBlob\Results;

use AzureBlob\Exceptions\AzureBlobException;
use JsonSerializable;
use Stringable;

/**
 * Conteúdo baixado de um blob.
 *
 * Guarda sempre os bytes crus. `encoding()` diz apenas se o conteúdo é
 * representável como texto UTF-8 — quem precisa transportar o valor em JSON usa
 * `toArray()`, que aplica base64 no caso binário, como fazia a ferramenta MCP.
 */
final class BlobContent implements JsonSerializable, Stringable
{
    public const ENCODING_TEXT = 'text';

    public const ENCODING_BASE64 = 'base64';

    /** Tipos MIME tratados como texto além de `text/*`. */
    private const TEXT_TYPES = [
        'application/json',
        'application/xml',
        'application/javascript',
        'application/x-yaml',
        'application/yaml',
        'application/ld+json',
        'image/svg+xml',
    ];

    public function __construct(
        public string $contents = '',
        public string $name = '',
        public ?string $contentType = null,
        public int $size = 0,
        public ?BlobProperties $properties = null,
    ) {}

    /** Os bytes crus, do jeito que vieram do Azure. */
    public function contents(): string
    {
        return $this->contents;
    }

    /**
     * Decodifica o conteúdo como JSON.
     *
     * @throws AzureBlobException Quando o corpo não é JSON válido.
     */
    public function json(bool $associative = true): mixed
    {
        $decoded = json_decode($this->contents, $associative);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AzureBlobException(
                sprintf('azure-blob: blob "%s" não contém JSON válido: %s.', $this->name, json_last_error_msg()),
                0,
                null,
                ['blob' => $this->name, 'content_type' => $this->contentType]
            );
        }

        return $decoded;
    }

    public function base64(): string
    {
        return base64_encode($this->contents);
    }

    /** Grava o conteúdo em disco; devolve os bytes escritos. */
    public function saveTo(string $path): int
    {
        $written = @file_put_contents($path, $this->contents);

        if ($written === false) {
            throw new AzureBlobException(
                sprintf('azure-blob: não foi possível gravar "%s" em "%s".', $this->name, $path),
                0,
                null,
                ['blob' => $this->name, 'path' => $path]
            );
        }

        return $written;
    }

    /** `text` quando o conteúdo é UTF-8 válido de um tipo textual, senão `base64`. */
    public function encoding(): string
    {
        return $this->isText() ? self::ENCODING_TEXT : self::ENCODING_BASE64;
    }

    public function isText(): bool
    {
        $type = strtolower(explode(';', (string) $this->contentType)[0]);

        $textual = str_starts_with($type, 'text/')
            || in_array($type, self::TEXT_TYPES, true)
            || str_ends_with($type, '+json')
            || str_ends_with($type, '+xml');

        // Um tipo textual não garante bytes decodificáveis: arquivos rotulados
        // como text/plain aparecem em latin-1 com frequência.
        return $textual && mb_check_encoding($this->contents, 'UTF-8');
    }

    public function humanSize(): string
    {
        return Size::human($this->size);
    }

    /**
     * Formato de transporte: texto direto quando possível, base64 caso contrário.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $isText = $this->isText();

        return [
            'name' => $this->name,
            'content' => $isText ? $this->contents : $this->base64(),
            'encoding' => $isText ? self::ENCODING_TEXT : self::ENCODING_BASE64,
            'content_type' => $this->contentType,
            'size' => $this->size,
        ];
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->contents;
    }
}
