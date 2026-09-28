import { BlobClient } from './blob-client.js';
import { type BlobLogger, Config, type ConnectionOptions, type HttpOptions } from './config.js';
import { type Env, optionsFromEnv } from './env.js';
import { ConfigurationError } from './errors.js';

export interface ManagerOptions {
    /** Conexão usada quando nenhuma é informada. Padrão: `default`. */
    default?: string;
    connections?: Readonly<Record<string, ConnectionOptions>>;
    /** Base de toda conexão; cada uma pode sobrescrever com a própria `http`. */
    http?: HttpOptions;
    logger?: BlobLogger | null;
}

/** Métodos do BlobClient repassados à conexão padrão pelo gerenciador. */
const DELEGATED = [
    'config',
    'containerName',
    'container',
    'readOnly',
    'isReadOnly',
    'info',
    'list',
    'listAll',
    'directory',
    'listNames',
    'files',
    'directories',
    'downloadText',
    'containerExists',
    'ensureContainer',
    'download',
    'downloadJson',
    'get',
    'stream',
    'downloadTo',
    'properties',
    'exists',
    'missing',
    'size',
    'lastModified',
    'mimeType',
    'url',
    'temporaryUrl',
    'sasUrl',
    'temporaryContainerUrl',
    'upload',
    'uploadJson',
    'uploadFile',
    'setMetadata',
    'delete',
    'deleteDirectory',
    'copy',
    'move',
] as const satisfies ReadonlyArray<keyof BlobClient>;

type Delegated = Pick<BlobClient, (typeof DELEGATED)[number]>;

/**
 * Gerenciador de conexões.
 *
 * Resolve cada conexão sob demanda e a mantém em cache — a resolução envolve
 * parsear SAS URL/connection string, o que não vale repetir a cada chamada.
 * Chamadas diretas vão para a conexão padrão, como a facade do Laravel:
 *
 *     const azure = BlobManager.fromEnv();
 *     await azure.list('apostilas/');                     // conexão padrão
 *     await azure.connection('contratos').upload('a.pdf', bytes);
 *     azure.build({ sasUrl }).list();                     // credencial avulsa
 */
export interface BlobManager extends Delegated {}

// biome-ignore lint/suspicious/noUnsafeDeclarationMerging: a interface acima declara os métodos delegados.
export class BlobManager {
    private readonly options: ManagerOptions;
    private readonly clients = new Map<string, BlobClient>();

    constructor(options: ManagerOptions = {}) {
        this.options = { ...options, connections: { ...(options.connections ?? {}) } };
    }

    /**
     * Gerenciador configurado pelas variáveis de ambiente, com os mesmos nomes
     * do SDK PHP. `overrides` completa ou sobrescreve o que veio do ambiente.
     */
    static fromEnv(env: Env = process.env, overrides: ManagerOptions = {}): BlobManager {
        const base = optionsFromEnv(env);

        return new BlobManager({
            ...base,
            ...overrides,
            connections: { ...base.connections, ...(overrides.connections ?? {}) },
            http: { ...(base.http ?? {}), ...(overrides.http ?? {}) },
        });
    }

    /** Cliente de uma conexão nomeada. Sem argumento, usa a conexão padrão. */
    connection(name?: string | null): BlobClient {
        const resolved = name?.trim() || this.defaultConnection();
        const cached = this.clients.get(resolved);

        if (cached !== undefined) {
            return cached;
        }

        const connection = this.options.connections?.[resolved];

        if (connection === undefined) {
            throw ConfigurationError.unknownConnection(resolved);
        }

        const client = this.build(connection, resolved);
        this.clients.set(resolved, client);

        return client;
    }

    /** Alias de `connection()`, no vocabulário de filesystem. */
    disk(name?: string | null): BlobClient {
        return this.connection(name);
    }

    /**
     * Cliente montado a partir de opções avulsas — útil para credenciais vindas
     * do banco (uma conta por cliente) sem registrá-las nas conexões.
     */
    build(connection: ConnectionOptions, name = 'ad-hoc'): BlobClient {
        return new BlobClient(
            Config.fromOptions(connection, name, { http: this.options.http, logger: this.options.logger }),
        );
    }

    /** Descarta os clientes já resolvidos, forçando releitura da configuração. */
    purge(name?: string): void {
        if (name === undefined) {
            this.clients.clear();
        } else {
            this.clients.delete(name);
        }
    }

    defaultConnection(): string {
        return this.options.default?.trim() || 'default';
    }

    connectionNames(): string[] {
        return Object.keys(this.options.connections ?? {});
    }
}

for (const method of DELEGATED) {
    Object.defineProperty(BlobManager.prototype, method, {
        value(this: BlobManager, ...args: unknown[]): unknown {
            const client = this.connection() as unknown as Record<string, (...rest: unknown[]) => unknown>;

            return client[method]?.(...args);
        },
        writable: true,
        configurable: true,
    });
}
