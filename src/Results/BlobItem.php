<?php

namespace AzureBlob\Results;

use AzureBlob\Support\Path;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;
use SimpleXMLElement;
use Throwable;

/**
 * Um blob (ou "diretório") devolvido pela listagem.
 *
 * Objeto de valor: os campos são preenchidos no construtor e não mudam depois.
 */
final class BlobItem implements JsonSerializable
{
    public function __construct(
        public string $name = '',
        public int $size = 0,
        public ?string $contentType = null,
        public ?DateTimeInterface $lastModified = null,
        public ?DateTimeInterface $createdOn = null,
        public ?string $etag = null,
        public ?string $contentMd5 = null,
        public ?string $blobType = null,
        public string $url = '',
        public bool $isDirectory = false,
    ) {}

    /**
     * Constrói a partir do nó `<Blob>` de uma resposta `List Blobs`.
     */
    public static function fromXml(SimpleXMLElement $node, string $containerUrl): self
    {
        $name = (string) ($node->Name ?? '');
        $properties = $node->Properties ?? null;

        return new self(
            name: $name,
            size: (int) self::value($properties, 'Content-Length'),
            contentType: self::nullable(self::value($properties, 'Content-Type')),
            lastModified: self::date(self::value($properties, 'Last-Modified')),
            createdOn: self::date(self::value($properties, 'Creation-Time')),
            etag: self::nullable(trim(self::value($properties, 'Etag'), '"')),
            contentMd5: self::nullable(self::value($properties, 'Content-MD5')),
            blobType: self::nullable(self::value($properties, 'BlobType')),
            url: rtrim($containerUrl, '/').'/'.Path::encode($name),
        );
    }

    /**
     * Constrói a partir do nó `<BlobPrefix>`, que representa uma "pasta"
     * quando a listagem usa delimitador.
     */
    public static function directory(SimpleXMLElement $node, string $containerUrl): self
    {
        $name = rtrim((string) ($node->Name ?? ''), '/');

        return new self(
            name: $name,
            url: rtrim($containerUrl, '/').'/'.Path::encode($name),
            isDirectory: true,
        );
    }

    public function basename(): string
    {
        return Path::basename($this->name);
    }

    public function dirname(): string
    {
        return Path::dirname($this->name);
    }

    /** Tamanho legível — usado pelo `azure:list`. */
    public function humanSize(): string
    {
        return Size::human($this->size);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'size' => $this->size,
            'content_type' => $this->contentType,
            'last_modified' => $this->lastModified?->format(DateTimeInterface::ATOM),
            'created_on' => $this->createdOn?->format(DateTimeInterface::ATOM),
            'etag' => $this->etag,
            'content_md5' => $this->contentMd5,
            'blob_type' => $this->blobType,
            'url' => $this->url,
            'is_directory' => $this->isDirectory,
        ];
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function value(?SimpleXMLElement $node, string $key): string
    {
        if ($node === null || ! isset($node->{$key})) {
            return '';
        }

        return trim((string) $node->{$key});
    }

    private static function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /** Datas do Azure vêm em RFC 1123 GMT; um valor ilegível não deve estourar. */
    private static function date(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
