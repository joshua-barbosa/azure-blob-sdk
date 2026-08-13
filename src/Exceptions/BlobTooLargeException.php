<?php

namespace AzureBlob\Exceptions;

/**
 * Lançada quando um download em memória excederia o limite configurado.
 *
 * Existe para proteger o processo PHP: use `stream()` ou `downloadTo()` para
 * arquivos grandes em vez de aumentar o limite indefinidamente.
 */
class BlobTooLargeException extends AzureBlobException
{
    public static function make(string $blob, int $size, int $max): self
    {
        return new self(
            sprintf(
                'azure-blob: blob "%s" tem %d bytes e excede o limite de %d bytes para download '
                .'em memória. Use stream() ou downloadTo(), ou ajuste "max_download_size".',
                $blob,
                $size,
                $max
            ),
            0,
            null,
            ['blob' => $blob, 'size' => $size, 'max_download_size' => $max]
        );
    }
}
