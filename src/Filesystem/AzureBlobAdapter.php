<?php

namespace AzureBlob\Filesystem;

use AzureBlob\BlobClient;
use AzureBlob\Exceptions\AzureBlobException;
use AzureBlob\Exceptions\BlobNotFoundException;
use AzureBlob\Results\BlobItem;
use AzureBlob\Support\Path;
use DateTimeInterface;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Throwable;

/**
 * Adaptador Flysystem 3 — Laravel 9 a 13.
 *
 * Traduz o contrato do Flysystem para o BlobClient. O Laravel 8 usa Flysystem
 * 1, cujo contrato é incompatível; ver AzureBlobAdapterV1.
 *
 * Visibilidade não é implementada de propósito: no Azure o nível de acesso
 * público é do container, não do blob. Fingir suporte por blob esconderia que a
 * chamada não teve efeito.
 */
class AzureBlobAdapter implements FilesystemAdapter
{
    public function __construct(
        private BlobClient $client,
        private string $prefix = '',
    ) {
        $this->prefix = Path::directoryPrefix($prefix);
    }

    public function client(): BlobClient
    {
        return $this->client;
    }

    public function fileExists(string $path): bool
    {
        try {
            return $this->client->exists($this->prefixed($path));
        } catch (Throwable $exception) {
            throw UnableToCheckFileExistence::forLocation($path, $exception);
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            return ! $this->client->list($this->prefixed(Path::directoryPrefix($path)), 1)->isEmpty();
        } catch (Throwable $exception) {
            throw UnableToCheckFileExistence::forLocation($path, $exception);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->put($path, $contents, $config);
    }

    /**
     * @param  resource  $contents
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->put($path, $contents, $config);
    }

    /**
     * @param  string|resource  $contents
     */
    private function put(string $path, $contents, Config $config): void
    {
        try {
            $this->client->upload($this->prefixed($path), $contents, $this->uploadOptions($config, $path));
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function read(string $path): string
    {
        try {
            return $this->client->get($this->prefixed($path));
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * @return resource
     */
    public function readStream(string $path)
    {
        try {
            return $this->client->stream($this->prefixed($path));
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->client->delete($this->prefixed($path));
        } catch (BlobNotFoundException) {
            // Apagar o que já não existe é o resultado desejado.
        } catch (Throwable $exception) {
            throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            $this->client->deleteDirectory($this->prefixed(Path::directoryPrefix($path)));
        } catch (Throwable $exception) {
            throw UnableToDeleteDirectory::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * O Azure não tem diretórios de verdade — eles existem enquanto houver um
     * blob com aquele prefixo. Criar não faz nada, e isso é o correto.
     */
    public function createDirectory(string $path, Config $config): void
    {
        // Intencionalmente vazio.
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation(
            $path,
            'O Azure Blob Storage define acesso público no container, não por blob.'
        );
    }

    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility(
            $path,
            'O Azure Blob Storage define acesso público no container, não por blob.'
        );
    }

    public function mimeType(string $path): FileAttributes
    {
        $attributes = $this->metadata($path, 'mimeType');

        if ($attributes->mimeType() === null) {
            throw UnableToRetrieveMetadata::mimeType($path, 'O blob não tem Content-Type definido.');
        }

        return $attributes;
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->metadata($path, 'lastModified');
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->metadata($path, 'fileSize');
    }

    private function metadata(string $path, string $type): FileAttributes
    {
        try {
            $properties = $this->client->properties($this->prefixed($path));
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::create($path, $type, $exception->getMessage(), $exception);
        }

        return new FileAttributes(
            path: $path,
            fileSize: $properties->size,
            lastModified: $properties->lastModified?->getTimestamp(),
            mimeType: $properties->contentType,
        );
    }

    /**
     * @return iterable<int,DirectoryAttributes|FileAttributes>
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = $this->prefixed(Path::directoryPrefix($path));

        if ($deep) {
            foreach ($this->client->listAll($prefix) as $item) {
                yield $this->fileAttributes($item);
            }

            return;
        }

        $marker = null;

        do {
            $page = $this->client->list(
                $prefix,
                BlobClient::MAX_PAGE,
                array_filter(['delimiter' => '/', 'marker' => $marker]),
            );

            foreach ($page->directories as $directory) {
                yield new DirectoryAttributes($this->unprefixed($directory->name));
            }

            foreach ($page->items as $item) {
                yield $this->fileAttributes($item);
            }

            $marker = $page->nextMarker;
        } while ($marker !== null);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->client->move($this->prefixed($source), $this->prefixed($destination));
        } catch (Throwable $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->client->copy($this->prefixed($source), $this->prefixed($destination));
        } catch (Throwable $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }
    }

    /**
     * Consultado por Storage::url(). O Laravel detecta o método por
     * `method_exists`, então a assinatura precisa ficar exatamente assim.
     */
    public function getUrl(string $path): string
    {
        return $this->client->url($this->prefixed($path));
    }

    /**
     * Consultado por Storage::temporaryUrl().
     *
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

    /** Prefixo do disco somado ao caminho pedido. */
    private function prefixed(string $path): string
    {
        return $this->prefix.Path::normalize($path);
    }

    /** Caminho devolvido ao Flysystem, sem o prefixo do disco. */
    private function unprefixed(string $path): string
    {
        if ($this->prefix !== '' && str_starts_with($path, $this->prefix)) {
            return substr($path, strlen($this->prefix));
        }

        return $path;
    }

    private function fileAttributes(BlobItem $item): FileAttributes
    {
        return new FileAttributes(
            path: $this->unprefixed($item->name),
            fileSize: $item->size,
            lastModified: $item->lastModified?->getTimestamp(),
            mimeType: $item->contentType,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function uploadOptions(Config $config, string $path): array
    {
        $options = [];

        $mimeType = $config->get('mimetype', $config->get('ContentType'));

        if (is_string($mimeType) && $mimeType !== '') {
            $options['content_type'] = $mimeType;
        }

        foreach (['CacheControl' => 'cache_control', 'ContentDisposition' => 'content_disposition', 'ContentEncoding' => 'content_encoding'] as $key => $option) {
            $value = $config->get($key);

            if (is_string($value) && $value !== '') {
                $options[$option] = $value;
            }
        }

        $metadata = $config->get('metadata');

        if (is_array($metadata)) {
            $options['metadata'] = $metadata;
        }

        return $options;
    }

    /** Reexpõe falhas do SDK para quem quiser inspecionar o motivo original. */
    public static function reason(Throwable $exception): ?string
    {
        return $exception instanceof AzureBlobException ? $exception->errorCode() : null;
    }
}
