<?php

namespace AzureBlob\Support;

use Symfony\Component\Mime\MimeTypes;

/**
 * Descoberta do Content-Type a partir da extensão.
 *
 * Sem isso o Azure grava tudo como `application/octet-stream` e o navegador
 * baixa o arquivo em vez de exibi-lo. Usa o symfony/mime quando disponível —
 * o Laravel já o traz — e cai numa tabela própria quando não.
 */
final class MimeType
{
    public const DEFAULT = 'application/octet-stream';

    /** Extensões suficientes para o caso comum, quando symfony/mime não está instalado. */
    private const FALLBACK = [
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'md' => 'text/markdown',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'yml' => 'application/yaml',
        'yaml' => 'application/yaml',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
    ];

    public static function guess(string $path): string
    {
        $extension = strtolower(pathinfo(Path::basename($path), PATHINFO_EXTENSION));

        if ($extension === '') {
            return self::DEFAULT;
        }

        if (class_exists(MimeTypes::class)) {
            $types = MimeTypes::getDefault()->getMimeTypes($extension);

            if ($types !== []) {
                return $types[0];
            }
        }

        return self::FALLBACK[$extension] ?? self::DEFAULT;
    }

    /** Detecta a partir do conteúdo — usado quando o nome não tem extensão. */
    public static function fromContents(string $contents, string $path = ''): string
    {
        $guessed = self::guess($path);

        if ($guessed !== self::DEFAULT) {
            return $guessed;
        }

        if ($contents === '') {
            return self::DEFAULT;
        }

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $detected = @finfo_buffer($finfo, $contents);
                finfo_close($finfo);

                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }

        return self::DEFAULT;
    }
}
