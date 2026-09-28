import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Readable } from 'node:stream';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { BlobManager } from '../../src/blob-manager.js';
import { Config } from '../../src/config.js';
import { BlobNotFoundError } from '../../src/errors.js';
import { RestClient } from '../../src/support/rest-client.js';

/**
 * Testes contra o Azurite, o emulador oficial do Azure Storage. É aqui que a
 * assinatura Shared Key e os SAS são validados por um servidor de verdade.
 *
 *     docker run -p 10000:10000 mcr.microsoft.com/azure-storage/azurite azurite-blob --blobHost 0.0.0.0
 *     AZURITE_URL=http://127.0.0.1:10000 npm run test:e2e
 */

const AZURITE_URL = process.env['AZURITE_URL'];
const ACCOUNT = 'devstoreaccount1';
// Chave pública e fixa do emulador, documentada pela Microsoft.
const KEY = 'Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==';
const RUN = `e2e${Date.now().toString(36)}`;
const CONTAINER = `${RUN}-docs`;
const BACKUP = `${RUN}-backup`;

async function createContainer(name: string): Promise<void> {
    const config = Config.fromOptions({ name: ACCOUNT, key: KEY, container: name, url: `${AZURITE_URL}/${ACCOUNT}` });

    await new RestClient(config).request('PUT', name, { query: { restype: 'container' } });
}

async function deleteContainer(name: string): Promise<void> {
    const config = Config.fromOptions({ name: ACCOUNT, key: KEY, container: name, url: `${AZURITE_URL}/${ACCOUNT}` });

    await new RestClient(config).request('DELETE', name, { query: { restype: 'container' }, allow: [404] });
}

describe.skipIf(AZURITE_URL === undefined)('Azurite (e2e)', () => {
    let manager: BlobManager;
    let directory: string;

    beforeAll(async () => {
        await createContainer(CONTAINER);
        await createContainer(BACKUP);
        directory = await mkdtemp(join(tmpdir(), 'azure-blob-e2e-'));

        const keyed = new BlobManager({
            connections: { default: { name: ACCOUNT, key: KEY, container: CONTAINER, url: `${AZURITE_URL}/${ACCOUNT}` } },
        });
        const containerSas = keyed.connection().temporaryContainerUrl(2, 'racwdl');

        manager = new BlobManager({
            connections: {
                default: { name: ACCOUNT, key: KEY, container: CONTAINER, url: `${AZURITE_URL}/${ACCOUNT}` },
                cs: {
                    connectionString: `DefaultEndpointsProtocol=http;AccountName=${ACCOUNT};AccountKey=${KEY};BlobEndpoint=${AZURITE_URL}/${ACCOUNT};`,
                    container: CONTAINER,
                },
                sas: { sasUrl: containerSas },
                blocos: { name: ACCOUNT, key: KEY, container: CONTAINER, url: `${AZURITE_URL}/${ACCOUNT}`, blockSize: 1 },
            },
        });
    });

    afterAll(async () => {
        await deleteContainer(CONTAINER);
        await deleteContainer(BACKUP);
        await rm(directory, { recursive: true, force: true });
    });

    it.each(['default', 'cs', 'sas'])('ciclo completo com a conexão "%s"', async (connection) => {
        const blob = manager.connection(connection);
        const name = `${connection}/Relatório (final) + anexos #1 100%.txt`;

        await blob.upload(name, 'conteúdo', { metadata: { origem: 'e2e' }, contentDisposition: 'inline' });

        expect(await blob.exists(name)).toBe(true);
        expect((await blob.download(name)).text()).toBe('conteúdo');

        const properties = await blob.properties(name);
        expect(properties.contentType).toBe('text/plain');
        expect(properties.metadata).toEqual({ origem: 'e2e' });
        expect(properties.contentDisposition).toBe('inline');

        expect((await blob.list(`${connection}/`)).names()).toEqual([name]);

        await blob.setMetadata(name, { revisado: 'sim' });
        expect((await blob.properties(name)).metadata).toEqual({ revisado: 'sim' });

        await expect(blob.upload(name, 'x', { overwrite: false })).rejects.toMatchObject({ status: 409 });
        expect(await blob.delete(name)).toBe(true);
        await expect(blob.get(name)).rejects.toBeInstanceOf(BlobNotFoundError);
    });

    it('upload em blocos (arquivo e stream) com download por stream para disco', async () => {
        const blob = manager.connection('blocos');
        const payload = Buffer.alloc(2.5 * 1024 * 1024);
        for (let index = 0; index < payload.length; index++) {
            payload[index] = index % 251;
        }
        const source = join(directory, 'grande.bin');
        await writeFile(source, payload);

        await blob.uploadFile('grande/arquivo.bin', source, { cacheControl: 'no-cache' });
        await blob.upload('grande/stream.bin', Readable.from([payload.subarray(0, 700_000), payload.subarray(700_000)]));

        for (const name of ['grande/arquivo.bin', 'grande/stream.bin']) {
            const target = join(directory, name.replace('/', '-'));
            expect(await blob.downloadTo(name, target)).toBe(payload.length);
            expect((await readFile(target)).equals(payload)).toBe(true);
        }

        expect((await blob.properties('grande/arquivo.bin')).cacheControl).toBe('no-cache');
        expect((await blob.properties('grande/arquivo.bin')).contentType).toBe('application/octet-stream');
    });

    it('paginação real com NextMarker e listagem rasa', async () => {
        const blob = manager.connection();

        await Promise.all(['pag/a.txt', 'pag/b.txt', 'pag/c.txt', 'pag/sub/d.txt'].map((name) => blob.upload(name, name)));

        const first = await blob.list('pag/', 2);
        expect(first.hasMore()).toBe(true);

        const all: string[] = [];
        for await (const item of blob.listAll('pag/')) {
            all.push(item.name);
        }
        expect(all).toEqual(['pag/a.txt', 'pag/b.txt', 'pag/c.txt', 'pag/sub/d.txt']);

        const shallow = await blob.directory('pag');
        expect(shallow.directories.map((item) => item.name)).toEqual(['pag/sub']);
        expect(await blob.deleteDirectory('pag')).toBe(4);
    });

    it('copy entre containers usa um SAS de origem, e move apaga a origem', async () => {
        const blob = manager.connection();
        await blob.upload('copia/origem.txt', 'copiar');

        await blob.copy('copia/origem.txt', 'destino.txt', { destinationContainer: BACKUP, wait: true });
        expect((await blob.container(BACKUP).get('destino.txt')).toString()).toBe('copiar');

        await manager.connection('sas').move('copia/origem.txt', 'copia/movido.txt');
        expect(await blob.exists('copia/origem.txt')).toBe(false);
        expect((await blob.get('copia/movido.txt')).toString()).toBe('copiar');
    });

    it('pastas, nomes, texto e container', async () => {
        const blob = manager.connection();

        for (const name of ['pastas/a.pdf', 'pastas/b.pdf', 'pastas/jan/c.pdf']) {
            await blob.upload(name, name, { cacheControl: 'no-cache' });
        }

        expect(await blob.files('pastas')).toEqual(['pastas/a.pdf', 'pastas/b.pdf']);
        expect(await blob.files('pastas', true)).toEqual(['pastas/a.pdf', 'pastas/b.pdf', 'pastas/jan/c.pdf']);
        expect(await blob.directories('pastas')).toEqual(['pastas/jan']);
        expect(await blob.listNames('pastas/', 2)).toEqual(['pastas/a.pdf', 'pastas/b.pdf']);
        expect(await blob.downloadText('pastas/a.pdf')).toBe('pastas/a.pdf');
        expect((await blob.properties('pastas/a.pdf')).cacheControl).toBe('no-cache');

        const target = join(directory, 'nova-pasta', 'a.pdf');
        await blob.downloadTo('pastas/a.pdf', target);
        expect(await readFile(target, 'utf8')).toBe('pastas/a.pdf');

        expect(await blob.containerExists()).toBe(true);
        expect(await blob.ensureContainer()).toBe(false);
        expect(await blob.container(`${RUN}-inexistente`).containerExists()).toBe(false);

        // O modo do azure.py original: uma SAS URL de container.
        expect(await manager.connection('sas').files('pastas')).toEqual(['pastas/a.pdf', 'pastas/b.pdf']);
        expect(await manager.connection('sas').containerExists()).toBe(true);
    });

    it('URLs temporárias abrem sem credencial; a pública não', async () => {
        const blob = manager.connection();
        await blob.upload('url/ação.json', '{"ok":true}');

        const signed = await fetch(blob.temporaryUrl('url/ação.json', 1, 'r', { contentType: 'text/plain' }));
        expect(signed.status).toBe(200);
        expect(await signed.text()).toBe('{"ok":true}');
        expect(signed.headers.get('content-type')).toBe('text/plain');

        const reused = await fetch(manager.connection('sas').temporaryUrl('url/ação.json'));
        expect(reused.status).toBe(200);

        expect((await fetch(blob.url('url/ação.json'))).status).not.toBe(200);
        expect(await blob.downloadJson('url/ação.json')).toEqual({ ok: true });
    });
});
