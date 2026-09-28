/**
 * Remove credenciais do contexto antes de escrever no log.
 *
 * Um SAS token no log é uma credencial vazada: quem lê o arquivo passa a ter o
 * mesmo acesso ao container até a expiração. Vale também para a chave da conta,
 * que não expira.
 */

export const MASK = '[REDACTED]';

/** Chaves cujo valor é apagado por completo. */
const SENSITIVE = new Set([
    'key',
    'account_key',
    'accountkey',
    'sas_token',
    'sastoken',
    'sas_url',
    'sasurl',
    'signature',
    'sig',
    'authorization',
    'connection_string',
    'connectionstring',
    'password',
    'secret',
    'shared_access_signature',
]);

export function scrub(context: Record<string, unknown>): Record<string, unknown> {
    const clean: Record<string, unknown> = {};

    for (const [key, value] of Object.entries(context)) {
        const normalized = key.toLowerCase().replace(/[- ]/g, '_');

        if (SENSITIVE.has(normalized)) {
            clean[key] = MASK;
        } else if (Array.isArray(value)) {
            clean[key] = value.map((item) => scrubValue(item));
        } else {
            clean[key] = scrubValue(value);
        }
    }

    return clean;
}

function scrubValue(value: unknown): unknown {
    if (typeof value === 'string') {
        return scrubUrl(value);
    }

    if (value !== null && typeof value === 'object' && !(value instanceof Date)) {
        return scrub(value as Record<string, unknown>);
    }

    return value;
}

/**
 * Substitui a assinatura de qualquer SAS embutido numa URL.
 *
 * URLs entram no contexto o tempo todo (`url`, `source`, `copy_source`) e
 * carregam o token inteiro na query string.
 */
export function scrubUrl(value: string): string {
    if (!value.includes('sig=')) {
        return value;
    }

    return value.replace(/([?&](?:sig|signature)=)[^&\s]+/gi, `$1${MASK}`);
}
