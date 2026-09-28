import { API_VERSION } from '../constants.js';
import { normalize, rawEncode } from './path.js';
import { hmacSha256 } from './shared-key-signer.js';

/**
 * Gera Service SAS de blob ou de container assinados com a chave da conta.
 *
 * O layout do string-to-sign vale para `sv` >= 2020-12-06; versões anteriores
 * têm menos campos e produziriam assinatura inválida.
 *
 * @see https://learn.microsoft.com/rest/api/storageservices/create-service-sas
 */

/** Instante de expiração, ou horas a partir de agora. */
export type Expiry = Date | number;

export interface SasOptions {
    start?: Date | null;
    ip?: string | null;
    /** Sobrescreve o Content-Type devolvido na leitura (`rsct`). */
    contentType?: string | null;
    /** Sobrescreve o Content-Disposition devolvido na leitura (`rscd`). */
    contentDisposition?: string | null;
}

/**
 * Ordem canônica das permissões. O Azure valida a string `sp` posicional:
 * "rw" é aceito, "wr" devolve AuthenticationFailed.
 */
const PERMISSION_ORDER = ['r', 'a', 'c', 'w', 'd', 'x', 'y', 'l', 't', 'f', 'm', 'e', 'o', 'p', 'i'];

/**
 * Deduplica e reordena as permissões conforme a ordem canônica, descartando
 * letras que o Azure não reconhece.
 */
export function normalizePermissions(permissions: string): string {
    const requested = new Set(permissions.trim().toLowerCase());
    const ordered = PERMISSION_ORDER.filter((letter) => requested.has(letter)).join('');

    return ordered === '' ? 'r' : ordered;
}

/** ISO 8601 em UTC sem milissegundos, o único formato aceito em `st`/`se`. */
export function formatSasDate(moment: Date): string {
    return moment.toISOString().replace(/\.\d{3}Z$/, 'Z');
}

/**
 * Aceita um instante explícito ou um número de horas a partir de agora — a
 * forma abreviada que o CLI e `temporaryUrl()` usam. O piso é uma hora.
 */
export function resolveExpiry(expiry: Expiry, now: Date = new Date()): Date {
    if (expiry instanceof Date) {
        return expiry;
    }

    const hours = Math.max(1, Math.trunc(Number.isFinite(expiry) ? expiry : 1));

    return new Date(now.getTime() + hours * 3_600_000);
}

export class SasBuilder {
    constructor(
        private readonly accountName: string,
        private readonly accountKey: string,
        private readonly apiVersion: string = API_VERSION,
    ) {}

    /** Token SAS (sem `?`) para um blob específico. */
    forBlob(container: string, blob: string, expiry: Expiry = 1, permissions = 'r', options: SasOptions = {}): string {
        return this.build(
            'b',
            `/blob/${this.accountName}/${trimSlashes(container)}/${normalize(blob)}`,
            expiry,
            permissions,
            options,
        );
    }

    /** Token SAS (sem `?`) para o container inteiro. */
    forContainer(container: string, expiry: Expiry = 1, permissions = 'rl', options: SasOptions = {}): string {
        return this.build('c', `/blob/${this.accountName}/${trimSlashes(container)}`, expiry, permissions, options);
    }

    private build(
        resource: 'b' | 'c',
        canonicalizedResource: string,
        expiry: Expiry,
        permissions: string,
        options: SasOptions,
    ): string {
        const signedPermissions = normalizePermissions(permissions);
        const signedExpiry = formatSasDate(resolveExpiry(expiry));
        const signedStart = options.start ? formatSasDate(options.start) : '';
        const signedIp = options.ip?.trim() ?? '';
        const contentDisposition = options.contentDisposition?.trim() ?? '';
        const contentType = options.contentType?.trim() ?? '';

        // Ordem posicional obrigatória para sv >= 2020-12-06. Os campos vazios
        // continuam ocupando a própria linha.
        const stringToSign = [
            signedPermissions,
            signedStart,
            signedExpiry,
            canonicalizedResource,
            '', // signedIdentifier (stored access policy)
            signedIp,
            '', // signedProtocol — vazio significa https,http
            this.apiVersion,
            resource,
            '', // signedSnapshotTime
            '', // signedEncryptionScope
            '', // rscc  Cache-Control
            contentDisposition, // rscd  Content-Disposition
            '', // rsce  Content-Encoding
            '', // rscl  Content-Language
            contentType, // rsct  Content-Type
        ].join('\n');

        const query: Array<[string, string]> = [
            ['sv', this.apiVersion],
            ['sr', resource],
            ['sp', signedPermissions],
            ['se', signedExpiry],
            ['sig', hmacSha256(this.accountKey, stringToSign)],
        ];

        if (signedStart !== '') {
            query.push(['st', signedStart]);
        }

        if (signedIp !== '') {
            query.push(['sip', signedIp]);
        }

        if (contentDisposition !== '') {
            query.push(['rscd', contentDisposition]);
        }

        if (contentType !== '') {
            query.push(['rsct', contentType]);
        }

        return query.map(([name, value]) => `${name}=${rawEncode(value)}`).join('&');
    }
}

function trimSlashes(value: string): string {
    return value.replace(/^\/+|\/+$/g, '');
}
