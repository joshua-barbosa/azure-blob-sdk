import { describe, expect, it } from 'vitest';
import { Config, bool, str } from '../../src/config.js';
import { connectionFromEnv, envPrefix, optionsFromEnv } from '../../src/env.js';
import { ConfigurationError } from '../../src/errors.js';

const SAS = 'https://conta.blob.core.windows.net/docs?sv=2022-11-02&sig=abc';

describe('Config.fromOptions', () => {
    it('SAS URL tem precedência e dispensa container', () => {
        const config = Config.fromOptions({ sasUrl: SAS, connectionString: 'AccountName=outra;AccountKey=x' });

        expect(config.authMode).toBe('sas_url');
        expect(config.container).toBe('docs');
        expect(config.accountName).toBe('conta');
        expect(config.usesSasToken()).toBe(true);
        expect(config.canSign()).toBe(false);
        expect(config.describe()).toBe('Conta: conta | Container: docs | Auth: SAS URL');
    });

    it('container explícito vence o da SAS URL', () => {
        expect(Config.fromOptions({ sasUrl: SAS, container: 'outro' }).container).toBe('outro');
    });

    it('connection string com chave assina; só com SAS vira modo SAS', () => {
        const keyed = Config.fromOptions({
            connectionString: 'AccountName=conta;AccountKey=a2V5;EndpointSuffix=core.windows.net',
            container: 'c',
        });
        const sasOnly = Config.fromOptions({
            connectionString: 'BlobEndpoint=https://conta.blob.core.windows.net;SharedAccessSignature=sv=1&sig=x',
            container: 'c',
        });

        expect(keyed.authMode).toBe('connection_string');
        expect(keyed.canSign()).toBe(true);
        expect(keyed.accountUrl).toBe('https://conta.blob.core.windows.net');
        expect(sasOnly.authMode).toBe('sas_url');
        expect(sasOnly.usesSasToken()).toBe(true);
    });

    it('conta + chave, com URL e sufixo customizáveis e chaves em snake_case', () => {
        const config = Config.fromOptions({
            name: 'conta',
            key: 'a2V5',
            container: 'c',
            endpoint_suffix: 'core.usgovcloudapi.net',
            max_download_size: '10',
            block_size: 1,
            readonly: 'true',
            api_version: '2023-01-03',
        });

        expect(config.authMode).toBe('account_key');
        expect(config.accountUrl).toBe('https://conta.blob.core.usgovcloudapi.net');
        expect(config.maxDownloadSize).toBe(10);
        expect(config.blockSize).toBe(1024 * 1024);
        expect(config.readonly).toBe(true);
        expect(config.apiVersion).toBe('2023-01-03');
        expect(config.describe()).toContain('[SOMENTE LEITURA]');
        expect(Config.fromOptions({ name: 'c', key: 'k', container: 'x', url: 'http://127.0.0.1:10000/c/' }).accountUrl).toBe(
            'http://127.0.0.1:10000/c',
        );
    });

    it('lança ConfigurationError quando falta credencial, container ou a URL é inválida', () => {
        expect(() => Config.fromOptions({ container: 'c' }, 'x')).toThrow(ConfigurationError);
        expect(() => Config.fromOptions({ container: 'c' }, 'x')).toThrow(/\[x\].*nenhuma credencial/);
        expect(() => Config.fromOptions({ name: 'a', key: 'b' })).toThrow(/container não configurado/);
        expect(() => Config.fromOptions({ sasUrl: 'https://sem-token/docs' })).toThrow(/sasUrl" inválida/);
        expect(() => Config.fromOptions({ name: '  ', key: '', sasUrl: '', container: 'c' })).toThrow(/nenhuma credencial/);
    });

    it('é imutável: withContainer e readOnly devolvem cópias', () => {
        const config = Config.fromOptions({ name: 'a', key: 'b', container: 'c' });

        expect(config.withContainer('c')).toBe(config);
        expect(config.withContainer(null)).toBe(config);
        expect(config.withContainer('d').container).toBe('d');
        expect(config.container).toBe('c');
        expect(config.readOnly(false)).toBe(config);
        expect(config.readOnly().readonly).toBe(true);
        expect(Object.isFrozen(config)).toBe(true);
        expect(config.blobUrl('a b/c.txt')).toBe('https://a.blob.core.windows.net/c/a%20b/c.txt');
    });

    it('herda http e logger compartilhados, com override por conexão', () => {
        const logger = { warn: () => undefined };
        const config = Config.fromOptions({ name: 'a', key: 'b', container: 'c', http: { timeout: '5' } }, 'x', {
            http: { timeout: 60 },
            logger,
        });

        expect(config.timeout).toBe(5);
        expect(config.logger).toBe(logger);
        expect(Config.fromOptions({ name: 'a', key: 'b', container: 'c', logger: null }, 'x', { logger }).logger).toBeNull();
    });
});

describe('helpers de coerção', () => {
    it('str trata vazio e aspas como ausência', () => {
        expect(str('  "valor"  ')).toBe('valor');
        expect(str('   ')).toBeNull();
        expect(str(true)).toBeNull();
        expect(str(10)).toBe('10');
    });

    it('bool entende strings do .env', () => {
        expect(bool('on', false)).toBe(true);
        expect(bool('false', true)).toBe(false);
        expect(bool(undefined, true)).toBe(true);
        expect(bool(1, false)).toBe(true);
    });
});

describe('env', () => {
    it('lê a conexão padrão e as extras com prefixo', () => {
        const options = optionsFromEnv({
            AZURE_BLOB_CONNECTION: 'apostilas',
            AZURE_BLOB_CONNECTIONS: 'apostilas, contratos-2026,',
            AZURE_STORAGE_SAS_URL: SAS,
            AZURE_TIMEOUT: '30',
            APOSTILAS_AZURE_STORAGE_NAME: 'conta',
            APOSTILAS_AZURE_READONLY: 'true',
            CONTRATOS_2026_AZURE_STORAGE_CONTAINER: 'contratos',
        });

        expect(options.default).toBe('apostilas');
        expect(Object.keys(options.connections ?? {})).toEqual(['default', 'apostilas', 'contratos-2026']);
        expect(options.connections?.['default']?.sasUrl).toBe(SAS);
        expect(options.connections?.['apostilas']).toMatchObject({ name: 'conta', readonly: 'true' });
        expect(options.connections?.['contratos-2026']?.container).toBe('contratos');
        expect(options.http).toEqual({ timeout: '30' });
    });

    it('prefixo normaliza o nome da conexão', () => {
        expect(envPrefix('contratos-2026')).toBe('CONTRATOS_2026_');
        expect(connectionFromEnv({ X_AZURE_TIMEOUT: '3' }, 'X_').http).toEqual({ timeout: '3' });
        expect(optionsFromEnv({}).default).toBe('default');
    });
});
