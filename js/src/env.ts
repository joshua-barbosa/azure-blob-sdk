import type { ConnectionOptions } from './config.js';
import type { ManagerOptions } from './blob-manager.js';

/**
 * Configuração a partir de variáveis de ambiente.
 *
 * Usa os mesmos nomes do SDK PHP (`AZURE_STORAGE_SAS_URL`, `AZURE_READONLY`…),
 * então um `.env` compartilhado atende às duas aplicações.
 *
 * Conexões extras são declaradas em `AZURE_BLOB_CONNECTIONS` (lista separada
 * por vírgula) e leem as mesmas variáveis com o nome da conexão como prefixo:
 *
 *     AZURE_BLOB_CONNECTIONS=apostilas,contratos
 *     APOSTILAS_AZURE_STORAGE_SAS_URL=https://...
 *     CONTRATOS_AZURE_STORAGE_CONNECTION_STRING=DefaultEndpointsProtocol=...
 */

export type Env = Readonly<Record<string, string | undefined>>;

/** Opções de uma conexão lidas com o prefixo dado (ex.: `APOSTILAS_`). */
export function connectionFromEnv(env: Env, prefix = ''): ConnectionOptions {
    const read = (name: string): string | undefined => env[`${prefix}${name}`];
    const timeout = read('AZURE_TIMEOUT');

    return {
        sasUrl: read('AZURE_STORAGE_SAS_URL'),
        connectionString: read('AZURE_STORAGE_CONNECTION_STRING'),
        name: read('AZURE_STORAGE_NAME'),
        key: read('AZURE_STORAGE_KEY'),
        container: read('AZURE_STORAGE_CONTAINER'),
        url: read('AZURE_STORAGE_URL'),
        endpointSuffix: read('AZURE_STORAGE_ENDPOINT_SUFFIX'),
        readonly: read('AZURE_READONLY'),
        maxDownloadSize: read('AZURE_MAX_DOWNLOAD_SIZE'),
        blockSize: read('AZURE_BLOCK_SIZE'),
        apiVersion: read('AZURE_API_VERSION'),
        ...(timeout === undefined ? {} : { http: { timeout } }),
    };
}

/** Converte "apostilas-2026" em "APOSTILAS_2026_", o prefixo das variáveis. */
export function envPrefix(connection: string): string {
    return `${connection.trim().toUpperCase().replace(/[^A-Z0-9]+/g, '_')}_`;
}

export function optionsFromEnv(env: Env = process.env): ManagerOptions {
    const extra = (env['AZURE_BLOB_CONNECTIONS'] ?? '')
        .split(',')
        .map((name) => name.trim())
        .filter((name) => name !== '' && name !== 'default');

    const connections: Record<string, ConnectionOptions> = { default: connectionFromEnv(env) };

    for (const name of extra) {
        connections[name] = connectionFromEnv(env, envPrefix(name));
    }

    const timeout = env['AZURE_TIMEOUT'];

    return {
        default: env['AZURE_BLOB_CONNECTION']?.trim() || 'default',
        connections,
        ...(timeout === undefined ? {} : { http: { timeout } }),
    };
}
