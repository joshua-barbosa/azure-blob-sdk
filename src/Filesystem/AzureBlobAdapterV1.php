<?php

namespace AzureBlob\Filesystem;

use AzureBlob\BlobClient;
use AzureBlob\Exceptions\BlobNotFoundException;
use AzureBlob\Results\BlobItem;
use AzureBlob\Support\Path;
use DateTimeInterface;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use Throwable;

/**
 * Adaptador Flysystem 1 — usado apenas no Laravel 8.
 *
 * O contrato do Flysystem 1 é bem diferente do 3: devolve arrays em vez de
 * objetos e sinaliza falha com `false` em vez de exceção. Esta classe existe
 * separada de AzureBlobAdapter porque não há como satisfazer as duas
 * interfaces ao mesmo tempo; o ServiceProvider escolhe qual instanciar.
 *
 * Como a classe só é carregada quando o Flysystem 1 está instalado, referenciar
 * AdapterInterface aqui não quebra as instalações com o Flysystem 3.
 */
class AzureBlobAdapterV1 implements AdapterInterface
{
    private string $prefix;

    public function __construct(
        private BlobClient $client,
        string $prefix = '',
    ) {
        $this->prefix = Path::directoryPrefix($prefix);
    }

    public function client(): BlobClient
    {
        return $this->client;
    }

    /** @return array<string,mixed>|false */
    public function write($path, $contents, Config $config)
    {
        return $this->put($path, $contents, $config);
    }

    /** @return array<string,mixed>|false */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->put($path, $resource, $config);
    }

    /** @return array<string,mixed>|false */
    public function update($path, $contents, Config $config)
    {
        return $this->put($path, $contents, $config);
    }

    /** @return array<string,mixed>|false */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->put($path, $resource, $config);
    }

    /**
     * @param  string|resource  $contents
     * @return array<string,mixed>|false
     */
    private function put(string $path, $contents, Config $config)
    {
        try {
            $this->client->upload($this->prefixed($path), $contents, $this->uploadOptions($config));

            return ['type' => 'file', 'path' => $path];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Diferente de write()/update(), o contrato do Flysystem 1 pede bool aqui —
     * devolver array faria Storage::move() entregar o array ao chamador.
     */
    public function rename($path, $newpath): bool
    {
        try {
            $this->client->move($this->prefixed($path), $this->prefixed($newpath));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function copy($path, $newpath): bool
    {
        try {
            $this->client->copy($this->prefixed($path), $this->prefixed($newpath));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function delete($path): bool
    {
        try {
            $this->client->delete($this->prefixed($path));

            return true;
        } catch (BlobNotFoundException) {
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function deleteDir($dirname): bool
    {
        try {
            $this->client->deleteDirectory($this->prefixed(Path::directoryPrefix($dirname)));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Diretórios não existem no Azure: eles aparecem sozinhos quando um blob
     * com aquele prefixo é criado.
     *
     * @return array<string,mixed>
     */
    public function createDir($dirname, Config $config)
    {
        return ['type' => 'dir', 'path' => $dirname];
    }

    /** @return array<string,mixed>|false */
    public function setVisibility($path, $visibility)
    {
        // Acesso público é definido no container, não por blob.
        return false;
    }

    /** @return array<string,mixed>|false */
    public function getVisibility($path)
    {
        return false;
    }

    /** @return array<string,mixed>|bool */
    public function has($path)
    {
        try {
            return $this->client->exists($this->prefixed($path));
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed>|false */
    public function read($path)
    {
        try {
            return ['type' => 'file', 'path' => $path, 'contents' => $this->client->get($this->prefixed($path))];
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed>|false */
    public function readStream($path)
    {
        try {
            return ['type' => 'file', 'path' => $path, 'stream' => $this->client->stream($this->prefixed($path))];
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function listContents($directory = '', $recursive = false)
    {
        $prefix = $this->prefixed(Path::directoryPrefix($directory));
        $contents = [];

        try {
            if ($recursive) {
                foreach ($this->client->listAll($prefix) as $item) {
                    $contents[] = $this->normalize($item);
                }

                return $contents;
            }

            $marker = null;

            do {
                $page = $this->client->list(
                    $prefix,
                    BlobClient::MAX_PAGE,
                    array_filter(['delimiter' => '/', 'marker' => $marker]),
                );

                foreach ($page->directories as $directoryItem) {
                    $contents[] = ['type' => 'dir', 'path' => $this->unprefixed($directoryItem->name)];
                }

                foreach ($page->items as $item) {
                    $contents[] = $this->normalize($item);
                }

                $marker = $page->nextMarker;
            } while ($marker !== null);
        } catch (Throwable) {
            return $contents;
        }

        return $contents;
    }

    /** @return array<string,mixed>|false */
    public function getMetadata($path)
    {
        try {
            $properties = $this->client->properties($this->prefixed($path));
        } catch (Throwable) {
            return false;
        }

        return [
            'type' => 'file',
            'path' => $path,
            'size' => $properties->size,
            'timestamp' => $properties->lastModified?->getTimestamp(),
            'mimetype' => $properties->contentType,
        ];
    }

    /** @return array<string,mixed>|false */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /** @return array<string,mixed>|false */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /** @return array<string,mixed>|false */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    public function getUrl(string $path): string
    {
        return $this->client->url($this->prefixed($path));
    }

    /**
     * @param  DateTimeInterface|int  $expiration
     * @param  array<string,mixed>  $options
     */
    public function getTemporaryUrl(string $path, $expiration, array $options = []): string
    {
        return $this->client->temporaryUrl(
            $this->prefixed($path),
            $expiration,
            (string) ($options['permissions'] ?? 'r'),
        );
    }

    /** @return array<string,mixed> */
    private function normalize(BlobItem $item): array
    {
        return [
            'type' => 'file',
            'path' => $this->unprefixed($item->name),
            'size' => $item->size,
            'timestamp' => $item->lastModified?->getTimestamp(),
            'mimetype' => $item->contentType,
        ];
    }

    private function prefixed(string $path): string
    {
        return $this->prefix.Path::normalize($path);
    }

    private function unprefixed(string $path): string
    {
        if ($this->prefix !== '' && str_starts_with($path, $this->prefix)) {
            return substr($path, strlen($this->prefix));
        }

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function uploadOptions(Config $config): array
    {
        $options = [];

        $mimeType = $config->get('mimetype', $config->get('ContentType'));

        if (is_string($mimeType) && $mimeType !== '') {
            $options['content_type'] = $mimeType;
        }

        $metadata = $config->get('metadata');

        if (is_array($metadata)) {
            $options['metadata'] = $metadata;
        }

        return $options;
    }
}
