import { API_VERSION, DEFAULTS, MAX_SINGLE_PUT } from './constants.js';
import { ConfigurationError } from './errors.js';
import { parseConnectionString } from './support/connection-string.js';
import { encode, rawEncode } from './support/path.js';
import { parseSasUrl } from './support/sas-url.js';

/**
 * Configuração de conexões.
 *
 * As chaves aceitam camelCase (`sasUrl`) e também snake_case (`sas_url`), o
 * formato do `config/azure-blob.php` do SDK PHP — assim um mesmo JSON serve às
 * duas linguagens.
 */

export type AuthMode = 'sas_url' | 'connection_string' | 'account_key';

/** Rótulos legíveis usados em `info()` e nos logs. */
export const AUTH_LABELS: Readonly<Record<AuthMode, string>> = {
    sas_url: 'SAS URL',
    connection_string: 'connection string',
    account_key: 'conta+chave',
};

/** O mínimo que o SDK usa de um logger: compatível com `console`. */
export interface BlobLogger {
    warn(message: string, context?: Record<string, unknown>): void;
}

export type FetchFunction = (input: string, init: RequestInit) => Promise<Response>;

export interface HttpOptions {
    /** Segundos até a resposta começar a chegar. */
    timeout?: number | string;
    /** `fetch` alternativo — útil em testes ou para instrumentar as chamadas. */
    fetch?: FetchFunction;
    /**
     * Dispatcher do undici repassado ao `fetch` nativo (ex.: um `ProxyAgent`
     * para sair por proxy, ou um `Agent` com TLS customizado).
     */
    dispatcher?: unknown;
}

export interface ConnectionOptions {
    sasUrl?: string | null;
    connectionString?: string | null;
    /** Nome da conta. */
    name?: string | null;
    /** Chave da conta (base64). */
    key?: string | null;
    container?: string | null;
    /** Endpoint da conta; preencha para domínio próprio ou para o Azurite. */
    url?: string | null;
    endpointSuffix?: string | null;
    /** Trava local de escrita. */
    readonly?: boolean | string | null;
    /** Teto para `download()` em memória, em bytes. */
    maxDownloadSize?: number | string | null;
    /** Tamanho de bloco no upload, em bytes. */
    blockSize?: number | string | null;
    apiVersion?: string | null;
    http?: HttpOptions;
    logger?: BlobLogger | null;
    /** Formato snake_case do SDK PHP, aceito como alternativa. */
    [snakeCase: string]: unknown;
}

export interface SharedOptions {
    http?: HttpOptions;
    logger?: BlobLogger | null;
}

export interface ConfigData {
    connection: string;
    authMode: AuthMode;
    accountName: string | null;
    accountKey: string | null;
    sasToken: string;
    accountUrl: string;
    container: string;
    readonly: boolean;
    maxDownloadSize: number;
    blockSize: number;
    apiVersion: string;
    timeout: number;
    fetch: FetchFunction | null;
    dispatcher: unknown;
    logger: BlobLogger | null;
}

/**
 * Configuração resolvida de uma conexão. Imutável: as variações
 * (`withContainer`, `readOnly`) devolvem uma nova instância.
 */
export class Config implements Readonly<ConfigData> {
    readonly connection!: string;
    readonly authMode!: AuthMode;
    readonly accountName!: string | null;
    readonly accountKey!: string | null;
    readonly sasToken!: string;
    readonly accountUrl!: string;
    readonly container!: string;
    readonly readonly!: boolean;
    readonly maxDownloadSize!: number;
    readonly blockSize!: number;
    readonly apiVersion!: string;
    readonly timeout!: number;
    readonly fetch!: FetchFunction | null;
    readonly dispatcher: unknown;
    readonly logger!: BlobLogger | null;

    constructor(data: ConfigData) {
        Object.assign(this, data);
        Object.freeze(this);
    }

    /**
     * Resolve uma conexão a partir das opções declaradas.
     *
     * A precedência entre modos de autenticação é SAS URL > connection string >
     * conta+chave, a mesma do SDK PHP e da ferramenta MCP original.
     */
    static fromOptions(options: ConnectionOptions, name = 'default', shared: SharedOptions = {}): Config {
        const suffix = str(pick(options, 'endpointSuffix', 'endpoint_suffix')) ?? DEFAULTS.endpointSuffix;
        const http = { ...(shared.http ?? {}), ...(options.http ?? {}) };

        let accountName = str(pick(options, 'name', 'accountName', 'account_name'));
        let accountKey = str(pick(options, 'key', 'accountKey', 'account_key'));
        let accountUrl = (str(pick(options, 'url', 'accountUrl', 'account_url')) ?? '').replace(/\/+$/, '');
        let container = str(options.container) ?? '';
        let sasToken = '';
        let authMode: AuthMode;

        const sasUrl = str(pick(options, 'sasUrl', 'sas_url'));
        const connectionString = str(pick(options, 'connectionString', 'connection_string'));

        if (sasUrl !== null) {
            const parsed = parseSasUrl(sasUrl);

            if (parsed === null) {
                throw ConfigurationError.invalidSasUrl(name);
            }

            authMode = 'sas_url';
            sasToken = parsed.sasToken;
            accountName ??= parsed.accountName || null;
            accountUrl ||= parsed.accountUrl;
            // O container explícito na conexão vence o que veio embutido na URL.
            container ||= parsed.container;
        } else if (connectionString !== null) {
            const parsed = parseConnectionString(connectionString, suffix);

            authMode = parsed.sasToken !== '' && parsed.accountKey === '' ? 'sas_url' : 'connection_string';
            sasToken = parsed.sasToken;
            accountName ??= parsed.accountName || null;
            accountKey ??= parsed.accountKey || null;
            accountUrl ||= parsed.endpoint;
        } else if (accountName !== null && accountKey !== null) {
            authMode = 'account_key';
            accountUrl ||= `https://${accountName}.blob.${suffix}`;
        } else {
            throw ConfigurationError.missingCredentials(name);
        }

        if (container === '') {
            throw ConfigurationError.missingContainer(name);
        }

        return new Config({
            connection: name,
            authMode,
            accountName,
            accountKey,
            sasToken,
            accountUrl,
            container,
            readonly: bool(options.readonly, DEFAULTS.readonly),
            maxDownloadSize: positiveInt(pick(options, 'maxDownloadSize', 'max_download_size'), DEFAULTS.maxDownloadSize),
            blockSize: blockSize(pick(options, 'blockSize', 'block_size')),
            apiVersion: str(pick(options, 'apiVersion', 'api_version')) ?? API_VERSION,
            timeout: positiveInt(http.timeout, DEFAULTS.timeout),
            fetch: http.fetch ?? null,
            dispatcher: http.dispatcher,
            logger: (options.logger === undefined ? shared.logger : options.logger) ?? null,
        });
    }

    /** Cópia apontando para outro container. */
    withContainer(container: string | null | undefined): Config {
        const resolved = str(container);

        if (resolved === null || resolved === this.container) {
            return this;
        }

        return new Config({ ...this.toData(), container: resolved });
    }

    /** Cópia com a trava de somente leitura ligada (ou desligada). */
    readOnly(readonly = true): Config {
        return readonly === this.readonly ? this : new Config({ ...this.toData(), readonly });
    }

    /** True quando é possível assinar requisições/SAS com a chave da conta. */
    canSign(): boolean {
        return this.accountName !== null && this.accountKey !== null;
    }

    usesSasToken(): boolean {
        return this.authMode === 'sas_url' || (this.sasToken !== '' && !this.canSign());
    }

    /** URL pública do blob, sem token. */
    blobUrl(blob: string, container?: string): string {
        return `${this.containerUrl(container)}/${encode(blob)}`;
    }

    containerUrl(container?: string): string {
        return `${this.accountUrl}/${rawEncode(container ?? this.container)}`;
    }

    /** Descrição legível da conexão, usada por `azure-blob info` e pelos logs. */
    describe(): string {
        let info = `Container: ${this.container}`;

        if (this.accountName !== null) {
            info = `Conta: ${this.accountName} | ${info}`;
        }

        info += ` | Auth: ${AUTH_LABELS[this.authMode]}`;

        if (this.readonly) {
            info += ' [SOMENTE LEITURA]';
        }

        return info;
    }

    private toData(): ConfigData {
        return { ...this };
    }
}

function pick(options: ConnectionOptions, ...keys: string[]): unknown {
    for (const key of keys) {
        if (options[key] !== undefined && options[key] !== null) {
            return options[key];
        }
    }

    return undefined;
}

/**
 * Trata string vazia ou só com espaços como ausência de valor: variáveis de
 * ambiente vazias são comuns e '' nunca é uma credencial válida.
 */
export function str(value: unknown): string | null {
    if (typeof value !== 'string' && typeof value !== 'number') {
        return null;
    }

    const trimmed = String(value).trim().replace(/^"+|"+$/g, '');

    return trimmed === '' ? null : trimmed;
}

export function bool(value: unknown, fallback: boolean): boolean {
    if (value === undefined || value === null) {
        return fallback;
    }

    if (typeof value === 'string') {
        return ['1', 'true', 'yes', 'on'].includes(value.trim().toLowerCase());
    }

    return Boolean(value);
}

function positiveInt(value: unknown, fallback: number): number {
    const parsed = typeof value === 'number' ? value : typeof value === 'string' ? Number(value.trim()) : Number.NaN;

    return Number.isFinite(parsed) && Math.trunc(parsed) > 0 ? Math.trunc(parsed) : fallback;
}

/**
 * O Azure exige blocos entre 1 byte e 4000 MiB e no máximo 50.000 blocos por
 * blob. O piso de 1 MiB evita estourar essa contagem em arquivos grandes.
 */
function blockSize(value: unknown): number {
    return Math.min(Math.max(positiveInt(value, DEFAULTS.blockSize), 1024 * 1024), MAX_SINGLE_PUT);
}
