<?php

namespace AzureBlob\Results;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;
use Throwable;

/**
 * Propriedades de um blob, extraídas dos cabeçalhos de `Get Blob Properties`.
 *
 * O Azure responde a um HEAD sem corpo: todo o metadado vem em cabeçalhos, e os
 * definidos pelo usuário aparecem com o prefixo `x-ms-meta-`.
 */
final class BlobProperties implements JsonSerializable
{
    /**
     * @param  array<string,string>  $metadata
     */
    public function __construct(
        public string $name = '',
        public string $container = '',
        public int $size = 0,
        public ?string $contentType = null,
        public ?string $contentMd5 = null,
        public ?string $contentEncoding = null,
        public ?string $cacheControl = null,
        public ?string $contentDisposition = null,
        public ?DateTimeInterface $lastModified = null,
        public ?DateTimeInterface $createdOn = null,
        public ?string $etag = null,
        public ?string $blobType = null,
        public ?string $accessTier = null,
        public array $metadata = [],
        public string $url = '',
    ) {}

    /**
     * @param  array<string,array<int,string>|string>  $headers
     */
    public static function fromHeaders(array $headers, string $name, string $container, string $url): self
    {
        $get = static function (string $key) use ($headers): ?string {
            foreach ($headers as $header => $value) {
                if (strcasecmp((string) $header, $key) === 0) {
                    $value = is_array($value) ? ($value[0] ?? '') : $value;
                    $value = trim((string) $value);

                    return $value === '' ? null : $value;
                }
            }

            return null;
        };

        $metadata = [];

        foreach ($headers as $header => $value) {
            if (stripos((string) $header, 'x-ms-meta-') === 0) {
                $metadata[substr((string) $header, 10)] = is_array($value) ? ($value[0] ?? '') : (string) $value;
            }
        }

        return new self(
            name: $name,
            container: $container,
            size: (int) ($get('Content-Length') ?? 0),
            contentType: $get('Content-Type'),
            contentMd5: $get('Content-MD5'),
            contentEncoding: $get('Content-Encoding'),
            cacheControl: $get('Cache-Control'),
            contentDisposition: $get('Content-Disposition'),
            lastModified: self::date($get('Last-Modified')),
            createdOn: self::date($get('x-ms-creation-time')),
            etag: $get('ETag') === null ? null : trim((string) $get('ETag'), '"'),
            blobType: $get('x-ms-blob-type'),
            accessTier: $get('x-ms-access-tier'),
            metadata: $metadata,
            url: $url,
        );
    }

    public function humanSize(): string
    {
        return Size::human($this->size);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'container' => $this->container,
            'size' => $this->size,
            'content_type' => $this->contentType,
            'content_md5' => $this->contentMd5,
            'content_encoding' => $this->contentEncoding,
            'cache_control' => $this->cacheControl,
            'content_disposition' => $this->contentDisposition,
            'last_modified' => $this->lastModified?->format(DateTimeInterface::ATOM),
            'created_on' => $this->createdOn?->format(DateTimeInterface::ATOM),
            'etag' => $this->etag,
            'blob_type' => $this->blobType,
            'access_tier' => $this->accessTier,
            'metadata' => $this->metadata,
            'url' => $this->url,
        ];
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
