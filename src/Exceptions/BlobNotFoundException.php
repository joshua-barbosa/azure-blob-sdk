<?php

namespace AzureBlob\Exceptions;

use Throwable;

/**
 * Lançada quando o Azure responde 404 para um blob ou container.
 */
class BlobNotFoundException extends AzureBlobException
{
    /**
     * @param  array<string,mixed>  $context  Contexto extra (ex.: ids de rastreio).
     */
    public static function make(string $blob, string $container, ?Throwable $previous = null, array $context = []): self
    {
        return new self(
            sprintf('azure-blob: blob "%s" não encontrado no container "%s".', $blob, $container),
            404,
            $previous,
            array_merge(
                ['blob' => $blob, 'container' => $container, 'status' => 404, 'error_code' => 'BlobNotFound'],
                $context,
            )
        );
    }
}
