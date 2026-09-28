import { createHmac } from 'node:crypto';
import { API_VERSION } from '../constants.js';

/**
 * Assinatura Shared Key (HMAC-SHA256) da REST API do Azure Storage.
 *
 * Implementa o esquema descrito em
 * https://learn.microsoft.com/rest/api/storageservices/authorize-with-shared-key
 *
 * O string-to-sign é posicional: qualquer cabeçalho fora de ordem, espaço
 * sobrando ou parâmetro de query esquecido produz um 403 AuthenticationFailed,
 * por isso cada etapa aqui é literal e sem atalhos.
 */

export type HeaderMap = Record<string, string>;

/**
 * Cabeçalhos padrão do string-to-sign, na ordem exata exigida pelo Azure.
 * `Date` fica vazio de propósito: usamos `x-ms-date`, que tem precedência.
 */
const SIGNED_HEADERS = [
    'content-encoding',
    'content-language',
    'content-length',
    'content-md5',
    'content-type',
    'date',
    'if-modified-since',
    'if-match',
    'if-none-match',
    'if-unmodified-since',
    'range',
] as const;

/** Data no formato RFC 1123 exigido por `x-ms-date`. */
export function httpDate(moment: Date = new Date()): string {
    return moment.toUTCString();
}

export function hmacSha256(accountKey: string, payload: string): string {
    return createHmac('sha256', Buffer.from(accountKey, 'base64')).update(payload, 'utf8').digest('base64');
}

export class SharedKeySigner {
    constructor(
        private readonly accountName: string,
        private readonly accountKey: string,
    ) {}

    /** Devolve os cabeçalhos da requisição já com `Authorization` calculado. */
    sign(method: string, url: string, headers: HeaderMap = {}, apiVersion: string = API_VERSION): HeaderMap {
        const complete = withRequiredHeaders(headers, apiVersion);
        const signature = hmacSha256(this.accountKey, this.stringToSign(method, url, complete));

        return { ...complete, Authorization: `SharedKey ${this.accountName}:${signature}` };
    }

    /**
     * Monta o string-to-sign. Exposto para os testes conferirem o formato
     * contra os vetores da documentação sem depender da chave.
     */
    stringToSign(method: string, url: string, headers: HeaderMap): string {
        const normalized = lowerKeys(headers);
        const lines = [method.toUpperCase(), ...SIGNED_HEADERS.map((header) => standardHeader(header, normalized))];

        return `${lines.join('\n')}\n${canonicalizedHeaders(normalized)}${this.canonicalizedResource(url)}`;
    }

    /**
     * Recurso canônico: `/conta/caminho` seguido de uma linha por parâmetro de
     * query, com nome em minúsculas e valores repetidos ordenados e unidos por
     * vírgula.
     */
    private canonicalizedResource(url: string): string {
        const questionMark = url.indexOf('?');
        const base = questionMark === -1 ? url : url.slice(0, questionMark);
        const query = questionMark === -1 ? '' : url.slice(questionMark + 1);

        const path = new URL(base).pathname;
        let resource = `/${this.accountName}${path === '' ? '/' : path}`;

        const parameters = new Map<string, string[]>();

        for (const pair of query.split('&')) {
            if (pair === '') {
                continue;
            }

            const separator = pair.indexOf('=');
            const rawName = separator === -1 ? pair : pair.slice(0, separator);
            const rawValue = separator === -1 ? '' : pair.slice(separator + 1);
            const name = decodeURIComponent(rawName).toLowerCase();

            parameters.set(name, [...(parameters.get(name) ?? []), decodeURIComponent(rawValue)]);
        }

        for (const name of [...parameters.keys()].sort(compareBytes)) {
            const values = [...(parameters.get(name) ?? [])].sort(compareBytes);
            resource += `\n${name}:${values.join(',')}`;
        }

        return resource;
    }
}

/**
 * `Content-Length` igual a zero entra como string vazia (comportamento exigido
 * a partir da versão 2015-02-21 da API).
 */
function standardHeader(header: string, headers: HeaderMap): string {
    const value = headers[header] ?? '';

    if (header === 'content-length' && (value === '0' || value === '')) {
        return '';
    }

    return value;
}

/**
 * Cabeçalhos `x-ms-*`: nome em minúsculas, ordenados lexicograficamente, valor
 * com espaços internos colapsados. Cada par ocupa uma linha própria.
 */
function canonicalizedHeaders(headers: HeaderMap): string {
    return Object.keys(headers)
        .filter((name) => name.startsWith('x-ms-'))
        .sort(compareBytes)
        .map((name) => `${name}:${(headers[name] ?? '').replace(/\s+/g, ' ').trim()}\n`)
        .join('');
}

/**
 * `x-ms-date` e `x-ms-version` são obrigatórios e precisam estar no
 * string-to-sign, então são preenchidos aqui e não pelo chamador.
 */
function withRequiredHeaders(headers: HeaderMap, apiVersion: string): HeaderMap {
    const normalized = lowerKeys(headers);
    const complete = { ...headers };

    if (normalized['x-ms-date'] === undefined) {
        complete['x-ms-date'] = httpDate();
    }

    if (normalized['x-ms-version'] === undefined) {
        complete['x-ms-version'] = apiVersion;
    }

    return complete;
}

function lowerKeys(headers: HeaderMap): HeaderMap {
    return Object.fromEntries(Object.entries(headers).map(([name, value]) => [name.toLowerCase(), String(value)]));
}

/** Ordenação por código de caractere, como o `ksort(SORT_STRING)` do PHP. */
function compareBytes(left: string, right: string): number {
    if (left === right) {
        return 0;
    }

    return left < right ? -1 : 1;
}
