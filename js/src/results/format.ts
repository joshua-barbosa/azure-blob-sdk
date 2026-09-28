/**
 * Formatação compartilhada pelos objetos de resultado.
 */

const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

/** Tamanho legível — equivalente ao `format_size` da ferramenta MCP original. */
export function humanSize(bytes: number, precision = 1): string {
    if (!Number.isFinite(bytes) || bytes < 0) {
        return '0 B';
    }

    let unit = 0;
    let value = bytes;

    while (value >= 1024 && unit < UNITS.length - 1) {
        value /= 1024;
        unit++;
    }

    return unit === 0 ? `${Math.trunc(value)} ${UNITS[unit]}` : `${value.toFixed(precision)} ${UNITS[unit]}`;
}

/**
 * Data no formato ATOM (`2026-01-06T12:00:00+00:00`), o mesmo que o SDK PHP
 * usa na serialização. Mantém as saídas JSON das duas linguagens idênticas.
 */
export function atom(date: Date | null): string | null {
    return date === null ? null : date.toISOString().replace(/\.\d{3}Z$/, '+00:00');
}

/** Datas do Azure vêm em RFC 1123 GMT; um valor ilegível não deve estourar. */
export function parseDate(value: string | null | undefined): Date | null {
    if (value === null || value === undefined || value.trim() === '') {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

export function nullable(value: string | null | undefined): string | null {
    const trimmed = value?.trim() ?? '';

    return trimmed === '' ? null : trimmed;
}
