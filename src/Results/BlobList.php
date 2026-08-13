<?php

namespace AzureBlob\Results;

use ArrayIterator;
use AzureBlob\Support\Xml;
use Countable;
use Illuminate\Support\Collection;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Resultado de uma listagem, com o marcador de continuação do Azure.
 *
 * O Azure pagina em no máximo 5.000 itens por resposta. `nextMarker` guarda o
 * ponto de retomada; `BlobClient::listAll()` usa isso para varrer tudo.
 *
 * @implements IteratorAggregate<int,BlobItem>
 */
final class BlobList implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param  array<int,BlobItem>  $items
     * @param  array<int,BlobItem>  $directories
     */
    public function __construct(
        public array $items = [],
        public array $directories = [],
        public ?string $nextMarker = null,
        public string $prefix = '',
        public string $container = '',
    ) {}

    /**
     * Constrói a partir do XML de `List Blobs`.
     */
    public static function fromXml(string $xml, string $containerUrl, string $container = ''): self
    {
        $parsed = Xml::parse($xml);

        $items = [];
        $directories = [];

        if (isset($parsed->Blobs->Blob)) {
            foreach ($parsed->Blobs->Blob as $node) {
                $items[] = BlobItem::fromXml($node, $containerUrl);
            }
        }

        if (isset($parsed->Blobs->BlobPrefix)) {
            foreach ($parsed->Blobs->BlobPrefix as $node) {
                $directories[] = BlobItem::directory($node, $containerUrl);
            }
        }

        $marker = isset($parsed->NextMarker) ? trim((string) $parsed->NextMarker) : '';

        return new self(
            items: $items,
            directories: $directories,
            nextMarker: $marker === '' ? null : $marker,
            prefix: isset($parsed->Prefix) ? (string) $parsed->Prefix : '',
            container: $container !== '' ? $container : (string) ($parsed['ContainerName'] ?? ''),
        );
    }

    /** Blobs e diretórios juntos, na ordem em que o Azure devolveu. */
    public function all(): array
    {
        return array_merge($this->directories, $this->items);
    }

    /** @return array<int,string> */
    public function names(): array
    {
        return array_map(static fn (BlobItem $item): string => $item->name, $this->items);
    }

    /** Soma dos tamanhos dos blobs desta página. */
    public function totalSize(): int
    {
        return array_sum(array_map(static fn (BlobItem $item): int => $item->size, $this->items));
    }

    public function hasMore(): bool
    {
        return $this->nextMarker !== null;
    }

    public function isEmpty(): bool
    {
        return $this->items === [] && $this->directories === [];
    }

    /** @return Collection<int,BlobItem> */
    public function collect(): Collection
    {
        return new Collection($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return Traversable<int,BlobItem> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'container' => $this->container,
            'prefix' => $this->prefix,
            'count' => count($this->items),
            'total_size' => $this->totalSize(),
            'next_marker' => $this->nextMarker,
            'directories' => array_map(static fn (BlobItem $item): array => $item->toArray(), $this->directories),
            'blobs' => array_map(static fn (BlobItem $item): array => $item->toArray(), $this->items),
        ];
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
