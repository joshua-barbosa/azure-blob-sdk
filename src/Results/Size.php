<?php

namespace AzureBlob\Results;

/**
 * Formatação de tamanhos em bytes.
 *
 * Equivalente ao `format_size` da ferramenta MCP original, usado pela saída dos
 * comandos Artisan.
 */
final class Size
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    public static function human(int $bytes, int $precision = 1): string
    {
        if ($bytes < 0) {
            return '0 B';
        }

        $unit = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $unit < count(self::UNITS) - 1) {
            $value /= 1024;
            $unit++;
        }

        return $unit === 0
            ? sprintf('%d %s', $value, self::UNITS[$unit])
            : sprintf('%.'.$precision.'f %s', $value, self::UNITS[$unit]);
    }
}
