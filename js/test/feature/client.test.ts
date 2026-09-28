import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Readable } from 'node:stream';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    AzureBlobError,
    BlobClient,
    BlobManager,
    BlobNotFoundError,
    BlobTooLargeError,
    ConfigurationError,
    ReadOnlyError,
    createAzureBlob,
} from '../../src/index.js';
import { KEY_CONNECTION, SAS_CONNECTION, fakeManager } from '../helpers/clients.js';
import { ACCOUNT_URL, SAS_TOKEN, error } from '../helpers/fake-azure.js';

let directory: string;

beforeEach(async () => {
    directory = await mkdtemp(join(tmpdir(), 'azure-blob-'));
});

afterEach(async () => {
    await rm(directory, { recursive: true, force: true });
});

describe.each([
    ['conta+chave', 'default'],
    ['SAS URL', 'sas'],
])('operações com %s', (_label, connection) => {
    it('upload, exists, properties, download e delete', async () => {
        const { azure, manager } = fakeManager();
        const blob = manager.connection(connection);

        const url = await blob.upload('/pasta//relatório final.txt', 'olá mundo', { metadata: { origem: 'teste', '1x': 'ignorado' } });

        expect(url).toBe(`${ACCOUNT_URL}/docs/pasta/relat%C3%B3rio%20final.txt`);
        expect(azure.blobs.get('docs/pasta/relatório final.txt')?.contentType).toBe('text/plain');
        expect(await blob.exists('pasta/relatório final.txt')).toBe(true);
        expect(await blob.missing('nada.txt')).toBe(true);

        const properties = await blob.properties('pasta/relatório final.txt');
        expect(properties.size).toBe(Buffer.byteLength('olá mundo'));
        expect(properties.metadata).toEqual({ origem: 'teste' });
        expect(await blob.size('pasta/relatório final.txt')).toBe(10);
        expect(await blob.mimeType('pasta/relatório final.txt')).toBe('text/plain');
        expect(await blob.lastModified('pasta/relatório final.txt')).toBeInstanceOf(Date);

        const content = await blob.download('pasta/relatório final.txt');
        expect(content.text()).toBe('olá mundo');
        expect(content.properties?.name).toBe('pasta/relatório final.txt');

        expect(await blob.delete('pasta/relatório final.txt')).toBe(true);
        expect(await blob.delete('pasta/relatório final.txt')).toBe(false);
    });

    it('JSON de ida e volta', async () => {
        const { manager } = fakeManager();
        const blob = manager.connection(connection);

        await blob.uploadJson('dados.json', { nome: 'ação', lista: [1, 2] });

        expect(await blob.downloadJson('dados.json')).toEqual({ nome: 'ação', lista: [1, 2] });
        expect((await blob.properties('dados.json')).contentType).toBe('application/json');
    });
});

describe('leitura', () => {
    it('list pagina, filtra e lista rasa com diretórios', async () => {
        const { azure, manager } = fakeManager();
        azure.put('docs/a/1.txt', '1');
        azure.put('docs/a/2.txt', '22');
        azure.put('docs/a/sub/3.txt', '333');
        azure.put('docs/b.txt', 'b');

        const page = await manager.list('a/', 2);
        expect(page.names()).toEqual(['a/1.txt', 'a/2.txt']);
        expect(page.hasMore()).toBe(true);

        const all: string[] = [];
        azure.pageSize = 1;
        for await (const item of manager.listAll('/a')) {
            all.push(item.name);
        }
        expect(all).toEqual(['a/1.txt', 'a/2.txt', 'a/sub/3.txt']);

        azure.pageSize = 5000;
        const shallow = await manager.directory('a');
        expect(shallow.names()).toEqual(['a/1.txt', 'a/2.txt']);
        expect(shallow.directories.map((item) => item.name)).toEqual(['a/sub']);

        const listRequest = azure.requests.find((request) => request.url.searchParams.get('comp') === 'list');
        expect(listRequest?.url.searchParams.get('maxresults')).toBe('2');
        expect((await manager.list(null, 99_999, { include: 'metadata' })).length).toBe(4);
    });

    it('download respeita maxDownloadSize; get e stream não', async () => {
        const { azure, manager } = fakeManager({
            connections: { pequeno: { ...KEY_CONNECTION, maxDownloadSize: 4 } },
        });
        azure.put('docs/grande.bin', Buffer.alloc(10, 7), 'application/octet-stream');
        const blob = manager.connection('pequeno');

        await expect(blob.download('grande.bin')).rejects.toBeInstanceOf(BlobTooLargeError);
        expect((await blob.download('grande.bin', { maxSize: 0 })).size).toBe(10);
        expect((await blob.get('grande.bin')).length).toBe(10);

        const chunks: Buffer[] = [];
        for await (const chunk of await blob.stream('grande.bin')) {
            chunks.push(chunk as Buffer);
        }
        expect(Buffer.concat(chunks)).toEqual(Buffer.alloc(10, 7));
    });

    it('downloadTo grava por stream e falha cedo em destino inválido', async () => {
        const { azure, manager } = fakeManager();
        azure.put('docs/a.pdf', Buffer.from('%PDF-1.7'), 'application/pdf');
        const target = join(directory, 'a.pdf');

        expect(await manager.downloadTo('a.pdf', target)).toBe(8);
        expect(await readFile(target, 'utf8')).toBe('%PDF-1.7');

        const before = azure.requests.length;
        // Um arquivo no lugar da pasta: nem criar a pasta nem abrir o destino dá certo.
        await writeFile(join(directory, 'arquivo'), 'x');
        await expect(manager.downloadTo('a.pdf', join(directory, 'arquivo', 'existe.pdf'))).rejects.toThrow(/não foi possível criar a pasta/);
        expect(azure.requests.length).toBe(before);
    });

    it('404 vira BlobNotFoundError, em GET e em stream', async () => {
        const { manager } = fakeManager();

        await expect(manager.get('nada.txt')).rejects.toBeInstanceOf(BlobNotFoundError);
        await expect(manager.stream('nada.txt')).rejects.toMatchObject({ status: 404, errorCode: 'BlobNotFound' });
        await expect(manager.properties('pasta/nada.txt')).rejects.toThrow('"pasta/nada.txt" não encontrado no container "docs"');
    });

    it('outros erros carregam status, código e são logados sem credencial', async () => {
        const warn = vi.fn();
        const { azure, manager } = fakeManager({ logger: { warn } });
        azure.respondOnce(() => true, () => error(403, 'AuthorizationPermissionMismatch', 'Sem permissão.'));

        const failure = await manager.connection('sas').get('a.txt').catch((caught: unknown) => caught);

        expect(failure).toBeInstanceOf(AzureBlobError);
        expect(failure).toMatchObject({ status: 403, errorCode: 'AuthorizationPermissionMismatch' });
        expect((failure as AzureBlobError).message).toBe('azure-blob: GET docs/a.txt falhou com HTTP 403 (AuthorizationPermissionMismatch).');
        expect((failure as AzureBlobError).context['error_message']).toBe('Sem permissão.');
        expect(warn).toHaveBeenCalledWith('azure-blob: requisição rejeitada pelo Azure.', expect.objectContaining({ status: 403 }));
        expect(JSON.stringify(warn.mock.calls)).not.toContain('abc%2Bdef');
    });

    it('assinatura errada é rejeitada pelo fake (sanidade do teste)', async () => {
        const { manager } = fakeManager({ connections: { errada: { ...KEY_CONNECTION, key: 'b3V0cmE=' } } });

        await expect(manager.connection('errada').get('a.txt')).rejects.toMatchObject({ status: 403, errorCode: 'AuthenticationFailed' });
    });

    it('falha de rede e timeout viram AzureBlobError sem vazar o token', async () => {
        const refused = new BlobManager({
            connections: { default: SAS_CONNECTION },
            http: { fetch: () => Promise.reject(new TypeError('fetch failed', { cause: new Error('ECONNREFUSED') })) },
        });

        const failure = (await refused.exists('a.txt').catch((caught: unknown) => caught)) as AzureBlobError;
        expect(failure.message).toBe('azure-blob: falha de comunicação com o Azure (fetch failed: ECONNREFUSED).');
        expect(String(failure.context['url'])).toContain('sig=[REDACTED]');

        const slow = new BlobManager({
            connections: { default: { ...KEY_CONNECTION, http: { timeout: 1 } } },
            http: {
                fetch: (_url, init) =>
                    new Promise((_resolve, reject) => {
                        init.signal?.addEventListener('abort', () => reject(new Error('aborted')));
                    }),
            },
        });

        vi.useFakeTimers();
        const pending = slow.get('a.txt').catch((caught: unknown) => caught);
        await vi.advanceTimersByTimeAsync(1100);
        vi.useRealTimers();

        expect(((await pending) as Error).message).toContain('tempo limite de 1s esgotado');
    });
});

describe('escrita', () => {
    it('upload grande vai em blocos de tamanho fixo e commita a lista', async () => {
        const { azure, manager } = fakeManager({ connections: { blocos: { ...KEY_CONNECTION, blockSize: 1 } } });
        const blob = manager.connection('blocos');
        const payload = Buffer.alloc(2.5 * 1024 * 1024, 1);

        await blob.upload('grande.bin', payload, { contentType: 'application/x-teste', cacheControl: 'no-cache', overwrite: false });

        const blocks = azure.requests.filter((request) => request.url.searchParams.get('comp') === 'block');
        const commit = azure.requests.find((request) => request.url.searchParams.get('comp') === 'blocklist');

        expect(blocks.map((request) => request.body.length)).toEqual([1024 * 1024, 1024 * 1024, 512 * 1024]);
        expect(new Set(blocks.map((request) => request.url.searchParams.get('blockid')?.length)).size).toBe(1);
        expect(commit?.headers.get('x-ms-blob-content-type')).toBe('application/x-teste');
        expect(commit?.headers.get('x-ms-blob-cache-control')).toBe('no-cache');
        expect(commit?.headers.get('if-none-match')).toBe('*');
        expect(azure.blobs.get('docs/grande.bin')?.body.equals(payload)).toBe(true);
    });

    it('streams (Node, web e iteráveis) decidem sozinhos entre PUT simples e blocos', async () => {
        const { azure, manager } = fakeManager({ connections: { blocos: { ...KEY_CONNECTION, blockSize: 1 } } });
        const blob = manager.connection('blocos');
        const mb = 1024 * 1024;

        await blob.upload('pequeno.txt', Readable.from(['a', 'b', 'c']));
        expect(azure.blobs.get('docs/pequeno.txt')?.body.toString()).toBe('abc');
        expect(azure.requests.some((request) => request.url.searchParams.has('comp'))).toBe(false);

        // Exatamente um bloco: ainda cabe num PUT simples.
        await blob.upload('exato.bin', Readable.from([Buffer.alloc(mb, 2)]));
        expect(azure.requests.some((request) => request.url.searchParams.get('comp') === 'block')).toBe(false);

        const web = new ReadableStream<Uint8Array>({
            start(controller) {
                controller.enqueue(new Uint8Array(mb));
                controller.enqueue(new Uint8Array(10));
                controller.close();
            },
        });
        await blob.upload('web.bin', web);
        expect(azure.blobs.get('docs/web.bin')?.body.length).toBe(mb + 10);

        async function* gerador(): AsyncGenerator<Uint8Array> {
            yield new Uint8Array(3);
        }
        await blob.upload('gerador.bin', gerador());
        await blob.upload('arraybuffer.bin', new Uint8Array([1, 2, 3]).buffer);
        await blob.upload('view.bin', new Uint8Array([9, 9, 9, 9]).subarray(1, 3));
        expect(azure.blobs.get('docs/view.bin')?.body).toEqual(Buffer.from([9, 9]));

        await expect(blob.upload('x', 42 as unknown as string)).rejects.toThrow(/não suportado/);
    });

    it('overwrite: false devolve 409 quando o blob existe', async () => {
        const { azure, manager } = fakeManager();
        azure.put('docs/a.txt', 'x');

        await expect(manager.upload('a.txt', 'y', { overwrite: false })).rejects.toMatchObject({ status: 409, errorCode: 'BlobAlreadyExists' });
    });

    it('uploadFile detecta o tipo e recusa arquivo inexistente', async () => {
        const { azure, manager } = fakeManager();
        const file = join(directory, 'nota.pdf');
        await writeFile(file, '%PDF');

        await manager.uploadFile('notas/nota.pdf', file);
        expect(azure.blobs.get('docs/notas/nota.pdf')?.contentType).toBe('application/pdf');

        await manager.uploadFile('notas/forcado', file, { contentType: 'text/plain' });
        expect(azure.blobs.get('docs/notas/forcado')?.contentType).toBe('text/plain');

        await expect(manager.uploadFile('x', join(directory, 'nao-existe'))).rejects.toThrow(/não existe ou não pode ser lido/);
        await expect(manager.uploadFile('x', directory)).rejects.toThrow(/não existe ou não pode ser lido/);
    });

    it('setMetadata, deleteDirectory, copy e move', async () => {
        const { azure, manager } = fakeManager();
        azure.put('docs/a.txt', 'a');
        azure.put('docs/tmp/1.txt', '1');
        azure.put('docs/tmp/2.txt', '2');

        expect(await manager.setMetadata('a.txt', { 'autor-nome': 'Ana', versao: 2 })).toBe(true);
        expect(azure.blobs.get('docs/a.txt')?.metadata).toEqual({ autor_nome: 'Ana', versao: '2' });

        expect(await manager.deleteDirectory('tmp')).toBe(2);

        const copied = await manager.copy('a.txt', 'b.txt', { destinationContainer: 'backup' });
        expect(copied).toBe(`${ACCOUNT_URL}/backup/b.txt`);
        expect(azure.blobs.get('backup/b.txt')?.body.toString()).toBe('a');
        const copyRequest = azure.requests.find((request) => request.headers.has('x-ms-copy-source'));
        expect(copyRequest?.headers.get('x-ms-copy-source')).toMatch(/\/docs\/a\.txt\?sv=.*&sr=b&sp=r&/);

        azure.copyPending = 1;
        await manager.move('a.txt', 'c.txt');
        expect(azure.blobs.has('docs/a.txt')).toBe(false);
        expect(azure.blobs.has('docs/c.txt')).toBe(true);
    });

    it('copy com wait desiste após o timeout', async () => {
        const { azure, manager } = fakeManager();
        azure.put('docs/a.txt', 'a');
        azure.copyPending = 1_000_000;

        await expect(manager.copy('a.txt', 'b.txt', { wait: true, timeout: 0.3 })).rejects.toThrow(/não terminou em 0.3 segundos/);
    });

    it('conexão somente leitura bloqueia antes de sair qualquer requisição', async () => {
        const { azure, manager } = fakeManager({ connections: { ro: { ...KEY_CONNECTION, readonly: true } } });
        const blob = manager.connection('ro');

        for (const call of [
            () => blob.upload('a', 'x'),
            () => blob.delete('a'),
            () => blob.deleteDirectory('a'),
            () => blob.copy('a', 'b'),
            () => blob.move('a', 'b'),
            () => blob.setMetadata('a', {}),
            () => manager.connection().readOnly().upload('a', 'x'),
        ]) {
            await expect(call()).rejects.toBeInstanceOf(ReadOnlyError);
        }

        expect(azure.requests).toHaveLength(0);
        expect(blob.isReadOnly()).toBe(true);
        expect(blob.readOnly()).toBe(blob);
    });

    it('uploadJson recusa dados não serializáveis', async () => {
        const { manager } = fakeManager();
        const circular: Record<string, unknown> = {};
        circular['self'] = circular;

        await expect(manager.uploadJson('x.json', circular)).rejects.toThrow(/não foi possível serializar/);
        await expect(manager.uploadJson('x.json', undefined)).rejects.toThrow(/não foi possível serializar/);
    });
});

describe('URLs', () => {
    it('SAS novo nos modos com chave; token reaproveitado no modo SAS URL', () => {
        const { manager } = fakeManager();

        const signed = new URL(manager.temporaryUrl('a b.pdf', new Date('2030-01-01T00:00:00Z'), 'wr'));
        expect(signed.pathname).toBe('/docs/a%20b.pdf');
        expect(signed.searchParams.get('sp')).toBe('rw');
        expect(signed.searchParams.get('se')).toBe('2030-01-01T00:00:00Z');
        expect(manager.sasUrl('a.pdf')).toContain('sr=b');
        expect(manager.temporaryContainerUrl()).toMatch(/\/docs\?sv=.*sr=c&sp=rl/);

        const sas = manager.connection('sas');
        expect(sas.temporaryUrl('a.pdf', 48, 'rw')).toBe(`${ACCOUNT_URL}/docs/a.pdf?${SAS_TOKEN}`);
        expect(sas.temporaryContainerUrl()).toBe(`${ACCOUNT_URL}/docs?${SAS_TOKEN}`);
        expect(manager.url('/x/y.txt')).toBe(`${ACCOUNT_URL}/docs/x/y.txt`);
    });

    it('SAS URL sem chave não consegue assinar um SAS de cópia para outra conexão', () => {
        const client = new BlobManager({ connections: { default: { connectionString: 'BlobEndpoint=https://x;SharedAccessSignature=sig=1', container: 'c' } } });

        expect(client.connection().config().usesSasToken()).toBe(true);
    });
});

describe('BlobManager', () => {
    it('resolve, cacheia, descarta e reporta conexões', () => {
        const { manager } = fakeManager({ default: 'sas' });

        expect(manager.connection()).toBe(manager.connection('sas'));
        expect(manager.disk('sas')).toBe(manager.connection('sas'));
        expect(manager.connectionNames()).toEqual(['default', 'sas']);
        expect(manager.defaultConnection()).toBe('sas');
        expect(manager.info()).toBe('Conta: contateste | Container: docs | Auth: SAS URL');
        expect(manager.containerName()).toBe('docs');
        expect(manager.isReadOnly()).toBe(false);

        const first = manager.connection('default');
        manager.purge('default');
        expect(manager.connection('default')).not.toBe(first);
        const second = manager.connection('default');
        manager.purge();
        expect(manager.connection('default')).not.toBe(second);

        expect(() => manager.connection('nenhuma')).toThrow(ConfigurationError);
        expect(manager.build({ name: 'a', key: 'b', container: 'c' })).toBeInstanceOf(BlobClient);
        expect(manager.container('outro').containerName()).toBe('outro');
        expect(manager.config().connection).toBe('sas');
    });

    it('fromEnv e createAzureBlob leem o ambiente, com overrides', () => {
        const manager = BlobManager.fromEnv(
            { AZURE_STORAGE_SAS_URL: `${ACCOUNT_URL}/docs?${SAS_TOKEN}`, AZURE_TIMEOUT: '9' },
            { connections: { extra: KEY_CONNECTION } },
        );

        expect(manager.connection().config().timeout).toBe(9);
        expect(manager.connectionNames()).toEqual(['default', 'extra']);
        expect(createAzureBlob({ connections: { default: KEY_CONNECTION } }).containerName()).toBe('docs');

        const previous = process.env['AZURE_STORAGE_SAS_URL'];
        process.env['AZURE_STORAGE_SAS_URL'] = `${ACCOUNT_URL}/env?${SAS_TOKEN}`;
        try {
            expect(createAzureBlob().containerName()).toBe('env');
        } finally {
            if (previous === undefined) {
                delete process.env['AZURE_STORAGE_SAS_URL'];
            } else {
                process.env['AZURE_STORAGE_SAS_URL'] = previous;
            }
        }
    });
});

describe('regressões da revisão', () => {
    it('cópia que termina "failed" lança erro, e move não apaga a origem', async () => {
        const { azure, manager } = fakeManager();
        azure.put('docs/a.txt', 'a');
        azure.copyPending = 1;
        azure.copyOutcome = 'failed';

        await expect(manager.move('a.txt', 'b.txt', { destinationContainer: 'backup' })).rejects.toThrow(
            'azure-blob: a cópia para "b.txt" terminou com status "failed" (403 AuthorizationFailure).',
        );
        expect(azure.blobs.has('docs/a.txt')).toBe(true);
    });

    it('move para o mesmo blob é recusado antes de qualquer requisição', async () => {
        const { azure, manager } = fakeManager();
        azure.put('docs/a.txt', 'a');

        await expect(manager.move('/a.txt', 'a.txt')).rejects.toThrow(/são o mesmo blob/);
        expect(azure.blobs.has('docs/a.txt')).toBe(true);
        expect(azure.requests).toHaveLength(0);

        await manager.move('a.txt', 'a.txt', { destinationContainer: 'backup' });
        expect(azure.blobs.has('backup/a.txt')).toBe(true);
    });

    it('downloadTo não destrói o arquivo existente quando o download falha', async () => {
        const { azure, manager } = fakeManager();
        const target = join(directory, 'existente.txt');
        await writeFile(target, 'versão boa');

        await expect(manager.downloadTo('nada.txt', target)).rejects.toBeInstanceOf(BlobNotFoundError);
        expect(await readFile(target, 'utf8')).toBe('versão boa');

        azure.put('docs/novo.txt', 'versão nova');
        await manager.downloadTo('novo.txt', target);
        expect(await readFile(target, 'utf8')).toBe('versão nova');

        const { readdir } = await import('node:fs/promises');
        expect((await readdir(directory)).filter((name) => name.endsWith('.part'))).toEqual([]);
    });

    it('upload que falha fecha o stream de origem', async () => {
        const { azure, manager } = fakeManager({ connections: { blocos: { ...KEY_CONNECTION, blockSize: 1 } } });
        azure.put('docs/existe.bin', 'x');
        const source = Readable.from((function* () {
            for (let index = 0; index < 5; index++) {
                yield Buffer.alloc(1024 * 1024);
            }
        })());

        azure.respondOnce(
            (request) => request.url.searchParams.get('comp') === 'block',
            () => error(403, 'AuthorizationFailure', 'Negado.'),
        );

        await expect(manager.connection('blocos').upload('grande.bin', source)).rejects.toMatchObject({ status: 403 });
        expect(source.destroyed).toBe(true);
    });

    it('recusa segmentos "." e ".." no nome do blob', async () => {
        const { azure, manager } = fakeManager();

        await expect(manager.upload('tenant-a/../tenant-b/x.txt', 'x')).rejects.toThrow(/segmentos "\." e "\.\."/);
        await expect(manager.get('./a.txt')).rejects.toThrow(/não são permitidos/);
        expect(azure.requests).toHaveLength(0);
        expect(await manager.upload('a/..b/.c', 'ok')).toContain('/docs/a/..b/.c');
    });

    it('deleteDirectory usa os nomes exatos da listagem e exige prefixo', async () => {
        const { azure, manager } = fakeManager();
        azure.put('docs/dir//duplo.txt', '1');
        azure.put('docs/dir/espaco ', '2');
        azure.put('docs/outro.txt', '3');

        expect(await manager.deleteDirectory('dir')).toBe(2);
        expect([...azure.blobs.keys()]).toEqual(['docs/outro.txt']);

        await expect(manager.deleteDirectory('')).rejects.toThrow(/exige um prefixo/);
        await expect(manager.deleteDirectory('/')).rejects.toThrow(/exige um prefixo/);
        expect(azure.blobs.has('docs/outro.txt')).toBe(true);
    });

    it('uploadFile tira o Content-Type do nome do blob antes do arquivo local', async () => {
        const { azure, manager } = fakeManager();
        const temp = join(directory, 'upload_8f3a');
        await writeFile(temp, '%PDF');

        await manager.uploadFile('docs/a.pdf', temp);
        expect(azure.blobs.get('docs/docs/a.pdf')?.contentType).toBe('application/pdf');
    });

    it('timeout durante a leitura do corpo é reportado como timeout', async () => {
        const slowBody = new BlobManager({
            connections: { default: { ...KEY_CONNECTION, http: { timeout: 1 } } },
            http: {
                fetch: async (_url, init) =>
                    new Response(
                        new ReadableStream({
                            start(controller) {
                                init.signal?.addEventListener('abort', () => controller.error(new Error('aborted')));
                            },
                        }),
                    ),
            },
        });

        vi.useFakeTimers();
        const pending = slowBody.get('a.txt').catch((caught: unknown) => caught);
        await vi.advanceTimersByTimeAsync(1100);
        vi.useRealTimers();

        expect(((await pending) as Error).message).toContain('tempo limite de 1s esgotado');
    });
});

describe('atalhos do azure.py (pastas, nomes, texto, container)', () => {
    it('listNames percorre as páginas e respeita o teto', async () => {
        const { azure, manager } = fakeManager();
        ['a.pdf', 'b.pdf', 'c.pdf'].forEach((name) => azure.put(`docs/apostilas/${name}`, name));
        azure.pageSize = 2;

        expect(await manager.listNames('apostilas/')).toEqual(['apostilas/a.pdf', 'apostilas/b.pdf', 'apostilas/c.pdf']);
        expect(await manager.listNames(null, 1)).toEqual(['apostilas/a.pdf']);
    });

    it('files e directories leem uma pasta, com paginação', async () => {
        const { azure, manager } = fakeManager();
        ['2026/a.pdf', '2026/b.pdf', '2026/jan/c.pdf', '2026/fev/d.pdf', 'fora.txt'].forEach((name) => azure.put(`docs/${name}`, name));
        azure.pageSize = 1;

        expect(await manager.files('/2026')).toEqual(['2026/a.pdf', '2026/b.pdf']);
        expect(await manager.files('2026', true)).toEqual(['2026/a.pdf', '2026/b.pdf', '2026/fev/d.pdf', '2026/jan/c.pdf']);
        expect(await manager.directories('2026/')).toEqual(['2026/fev', '2026/jan']);
        expect(await manager.directories()).toEqual(['2026']);
        expect(await manager.files()).toEqual(['fora.txt']);
    });

    it('downloadText decodifica UTF-8', async () => {
        const { azure, manager } = fakeManager();
        azure.put('docs/a.txt', 'olá, mundo');

        expect(await manager.downloadText('a.txt')).toBe('olá, mundo');
    });

    it('containerExists e ensureContainer', async () => {
        const { azure, manager } = fakeManager();

        expect(await manager.containerExists()).toBe(true);
        expect(await manager.container('novo').containerExists()).toBe(false);
        expect(await manager.connection('sas').containerExists()).toBe(true);
        expect(azure.requests.at(-1)?.url.searchParams.get('comp')).toBe('list');
        expect(await manager.container('novo').ensureContainer()).toBe(true);
        expect(await manager.container('novo').ensureContainer()).toBe(false);
        expect(azure.containers.has('novo')).toBe(true);

        azure.respondOnce(
            (request) => request.method === 'PUT',
            () => error(409, 'ContainerBeingDeleted', 'Apagando.'),
        );
        await expect(manager.ensureContainer()).rejects.toThrow(/ContainerBeingDeleted/);

        await expect(manager.connection().readOnly().ensureContainer()).rejects.toBeInstanceOf(ReadOnlyError);
    });

    it('downloadTo cria a pasta de destino', async () => {
        const { azure, manager } = fakeManager();
        azure.put('docs/a.txt', 'conteúdo');
        const target = join(directory, 'nova', 'sub', 'a.txt');

        await manager.downloadTo('a.txt', target);
        expect(await readFile(target, 'utf8')).toBe('conteúdo');
    });
});
