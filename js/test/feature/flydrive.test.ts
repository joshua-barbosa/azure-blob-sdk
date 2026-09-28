import { Readable } from 'node:stream';
import { Disk } from 'flydrive';
import { describe, expect, it } from 'vitest';
import { BlobClient } from '../../src/blob-client.js';
import { Config } from '../../src/config.js';
import { AzureBlobDriver, expiresAt } from '../../src/integrations/flydrive.js';
import { KEY_CONNECTION, SAS_CONNECTION } from '../helpers/clients.js';
import { ACCOUNT_URL, FakeAzure, SAS_TOKEN } from '../helpers/fake-azure.js';

function setup(prefix?: string): { azure: FakeAzure; driver: AzureBlobDriver; disk: Disk } {
    const azure = new FakeAzure();
    const client = new BlobClient(Config.fromOptions({ ...KEY_CONNECTION, http: { fetch: azure.fetch } }));
    const driver = new AzureBlobDriver(client, prefix === undefined ? {} : { prefix });

    return { azure, driver, disk: new Disk(driver) };
}

async function text(stream: Readable): Promise<string> {
    const chunks: Buffer[] = [];

    for await (const chunk of stream) {
        chunks.push(Buffer.from(chunk as Uint8Array));
    }

    return Buffer.concat(chunks).toString('utf8');
}

// O FlyDrive 2 declara Node 24+. Funciona no 22; no 20 não há garantia.
const NODE_MAJOR = Number(process.versions.node.split('.')[0]);

describe.skipIf(NODE_MAJOR < 22)('AzureBlobDriver com o Disk do FlyDrive', () => {
    it('escreve, lê e consulta metadados', async () => {
        const { azure, disk } = setup();

        await disk.put('avatars/1.txt', 'olá', { contentType: 'text/plain', cacheControl: 'max-age=60', metadata: { dono: 'ana' } });
        await disk.putStream('avatars/2.txt', Readable.from(['stream']));

        expect(await disk.exists('avatars/1.txt')).toBe(true);
        expect(await disk.get('avatars/1.txt')).toBe('olá');
        expect(new TextDecoder().decode(await disk.getBytes('avatars/1.txt'))).toBe('olá');
        expect(await text(await disk.getStream('avatars/2.txt'))).toBe('stream');
        expect(await disk.getMetaData('avatars/1.txt')).toMatchObject({ contentType: 'text/plain', contentLength: 4, etag: '0x8DC0000000000' });
        expect(azure.blobs.get('docs/avatars/1.txt')?.headers['cache-control']).toBe('max-age=60');
        expect(azure.blobs.get('docs/avatars/1.txt')?.metadata).toEqual({ dono: 'ana' });
    });

    it('copia, move, apaga (sem erro para ausente) e apaga por prefixo', async () => {
        const { azure, disk } = setup();
        await disk.put('a.txt', 'a');

        await disk.copy('a.txt', 'b.txt');
        await disk.move('b.txt', 'c.txt');
        expect([...azure.blobs.keys()].sort()).toEqual(['docs/a.txt', 'docs/c.txt']);

        await disk.delete('nada.txt');
        await disk.delete('a.txt');
        await disk.put('tmp/1.txt', '1');
        await disk.put('tmp/2.txt', '2');
        await disk.deleteAll('tmp');
        expect([...azure.blobs.keys()]).toEqual(['docs/c.txt']);
    });

    it('lista raso ou recursivo, com paginação e prefixo de disco', async () => {
        const { azure, driver, disk } = setup('raiz');
        azure.put('docs/raiz/a.txt', 'a');
        azure.put('docs/raiz/pasta/b.txt', 'b');
        azure.put('docs/fora.txt', 'x');

        const shallow = [...(await disk.listAll('/')).objects];
        expect(shallow.map((object) => `${object.isFile ? 'arquivo' : 'pasta'}:${object.isFile ? object.key : object.prefix}`)).toEqual([
            'pasta:pasta',
            'arquivo:a.txt',
        ]);

        const deep = [...(await disk.listAll('', { recursive: true })).objects];
        expect(deep.map((object) => (object.isFile ? object.key : ''))).toEqual(['a.txt', 'pasta/b.txt']);

        azure.pageSize = 1;
        const firstPage = await driver.listAll('', { recursive: true });
        expect(firstPage.paginationToken).toBe('1');
        const secondPage = await driver.listAll('', { recursive: true, paginationToken: firstPage.paginationToken as string });
        expect([...secondPage.objects]).toHaveLength(1);
        expect(secondPage.paginationToken).toBeUndefined();
    });

    it('URLs públicas e assinadas', async () => {
        const { disk } = setup('raiz');

        expect(await disk.getUrl('a b.pdf')).toBe(`${ACCOUNT_URL}/docs/raiz/a%20b.pdf`);

        const signed = new URL(await disk.getSignedUrl('a.pdf', { expiresIn: '2 hours', contentDisposition: 'attachment' }));
        expect(signed.searchParams.get('sp')).toBe('r');
        expect(signed.searchParams.get('rscd')).toBe('attachment');

        const upload = new URL(await disk.getSignedUploadUrl('a.pdf', { expiresIn: 60 }));
        expect(upload.searchParams.get('sp')).toBe('cw');
    });

    it('visibilidade é recusada com mensagem explícita', async () => {
        const { driver } = setup();

        await expect(driver.getVisibility('a')).rejects.toThrow(/no container, não por blob/);
        await expect(driver.setVisibility('a', 'public')).rejects.toThrow(/no container, não por blob/);
        await expect(driver.put('a', 'x', { visibility: 'public' })).rejects.toThrow(/no container, não por blob/);
    });

    it('bucket troca de container e o construtor aceita opções de conexão', async () => {
        const { driver } = setup('raiz');

        expect(driver.bucket('outro').client.containerName()).toBe('outro');
        expect(new AzureBlobDriver(SAS_CONNECTION).client.info()).toContain('Auth: SAS URL');
        expect(await new AzureBlobDriver(SAS_CONNECTION).getUrl('a')).toBe(`${ACCOUNT_URL}/docs/a`);
        expect(SAS_TOKEN).toContain('sig=');
    });

    it('expiresAt entende segundos e expressões', () => {
        const now = new Date('2026-01-01T00:00:00Z');

        expect(expiresAt(undefined, now).toISOString()).toBe('2026-01-01T00:30:00.000Z');
        expect(expiresAt(90, now).toISOString()).toBe('2026-01-01T00:01:30.000Z');
        expect(expiresAt('30mins', now).toISOString()).toBe('2026-01-01T00:30:00.000Z');
        expect(expiresAt('1d', now).toISOString()).toBe('2026-01-02T00:00:00.000Z');
        expect(expiresAt('45', now).toISOString()).toBe('2026-01-01T00:00:45.000Z');
        expect(() => expiresAt('amanhã', now)).toThrow(/expiresIn "amanhã" inválido/);
    });
});
