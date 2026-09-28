import { describe, expect, it } from 'vitest';
import { AzureBlobError, BlobNotFoundError, BlobTooLargeError, ConfigurationError, ReadOnlyError } from '../../src/errors.js';
import { BlobContent } from '../../src/results/blob-content.js';
import { BlobItem } from '../../src/results/blob-item.js';
import { BlobList } from '../../src/results/blob-list.js';
import { BlobProperties } from '../../src/results/blob-properties.js';
import { atom, humanSize, parseDate } from '../../src/results/format.js';

describe('format', () => {
    it('humanSize espelha o format_size do MCP', () => {
        expect(humanSize(0)).toBe('0 B');
        expect(humanSize(1023)).toBe('1023 B');
        expect(humanSize(1536)).toBe('1.5 KB');
        expect(humanSize(5 * 1024 ** 3)).toBe('5.0 GB');
        expect(humanSize(-1)).toBe('0 B');
    });

    it('datas no formato ATOM e parse tolerante', () => {
        expect(atom(new Date('2026-01-06T12:00:00Z'))).toBe('2026-01-06T12:00:00+00:00');
        expect(atom(null)).toBeNull();
        expect(parseDate('lixo')).toBeNull();
        expect(parseDate('Tue, 06 Jan 2026 12:00:00 GMT')?.toISOString()).toBe('2026-01-06T12:00:00.000Z');
    });
});

describe('BlobList e BlobItem', () => {
    const list = BlobList.fromXml(
        '<EnumerationResults ContainerName="docs"><Prefix></Prefix><Blobs>' +
            '<Blob><Name>a/b.txt</Name><Properties><Content-Length>2048</Content-Length><Etag>"0x1"</Etag>' +
            '<Content-Type>text/plain</Content-Type><Last-Modified>Tue, 06 Jan 2026 12:00:00 GMT</Last-Modified>' +
            '<Content-MD5 /></Properties></Blob>' +
            '<BlobPrefix><Name>a/sub/</Name></BlobPrefix></Blobs><NextMarker /></EnumerationResults>',
        'https://conta.blob.core.windows.net/docs/',
    );

    it('monta itens, diretórios e agregados', () => {
        const [item] = list.items;

        expect(list.container).toBe('docs');
        expect(list.hasMore()).toBe(false);
        expect(list.isEmpty()).toBe(false);
        expect(list.length).toBe(1);
        expect([...list].map((blob) => blob.name)).toEqual(['a/b.txt']);
        expect(list.names()).toEqual(['a/b.txt']);
        expect(list.totalSize()).toBe(2048);
        expect(list.all().map((blob) => blob.name)).toEqual(['a/sub', 'a/b.txt']);
        expect(item?.etag).toBe('0x1');
        expect(item?.contentMd5).toBeNull();
        expect(item?.basename()).toBe('b.txt');
        expect(item?.dirname()).toBe('a');
        expect(item?.humanSize()).toBe('2.0 KB');
        expect(item?.url).toBe('https://conta.blob.core.windows.net/docs/a/b.txt');
        expect(list.directories[0]?.isDirectory).toBe(true);
    });

    it('toJSON segue o formato do SDK PHP', () => {
        expect(list.toJSON()).toMatchObject({
            container: 'docs',
            count: 1,
            total_size: 2048,
            next_marker: null,
            blobs: [{ name: 'a/b.txt', content_type: 'text/plain', last_modified: '2026-01-06T12:00:00+00:00' }],
            directories: [{ name: 'a/sub', is_directory: true }],
        });
        expect(new BlobList().isEmpty()).toBe(true);
        expect(new BlobItem({ name: 'x' }).toJSON()).toMatchObject({ name: 'x', size: 0, url: '' });
    });
});

describe('BlobProperties', () => {
    it('lê cabeçalhos e metadados', () => {
        const properties = BlobProperties.fromHeaders(
            new Headers({
                'content-length': '10',
                'content-type': 'application/pdf',
                etag: '"0x2"',
                'x-ms-meta-origem': 'scanner',
                'x-ms-access-tier': 'Hot',
                'last-modified': 'Tue, 06 Jan 2026 12:00:00 GMT',
            }),
            'a.pdf',
            'docs',
            'https://x/docs/a.pdf',
        );

        expect(properties.size).toBe(10);
        expect(properties.etag).toBe('0x2');
        expect(properties.metadata).toEqual({ origem: 'scanner' });
        expect(properties.humanSize()).toBe('10 B');
        expect(properties.toJSON()).toMatchObject({
            access_tier: 'Hot',
            metadata: { origem: 'scanner' },
            last_modified: '2026-01-06T12:00:00+00:00',
            created_on: null,
        });
    });
});

describe('BlobContent', () => {
    it('texto UTF-8 de tipo textual sai como text; binário sai em base64', () => {
        const text = new BlobContent({ contents: Buffer.from('olá'), name: 'a.txt', contentType: 'text/plain; charset=utf-8' });
        const latin1 = new BlobContent({ contents: Buffer.from([0xe9]), name: 'b.txt', contentType: 'text/plain' });
        const binary = new BlobContent({ contents: Buffer.from([1, 2]), name: 'c.bin', contentType: null });

        expect(text.encoding()).toBe('text');
        expect(text.toJSON()).toEqual({ name: 'a.txt', content: 'olá', encoding: 'text', content_type: 'text/plain; charset=utf-8', size: 4 });
        expect(String(text)).toBe('olá');
        expect(latin1.encoding()).toBe('base64');
        expect(binary.toJSON()).toMatchObject({ content: 'AQI=', encoding: 'base64' });
        expect(binary.humanSize()).toBe('2 B');
        expect(new BlobContent({ contents: Buffer.from('{}'), name: 'x', contentType: 'application/vnd.api+json' }).isText()).toBe(true);
    });

    it('json() decodifica ou lança AzureBlobError', () => {
        expect(new BlobContent({ contents: Buffer.from('{"a":1}'), name: 'x.json' }).json()).toEqual({ a: 1 });
        expect(() => new BlobContent({ contents: Buffer.from('{'), name: 'x.json' }).json()).toThrow(/não contém JSON válido/);
    });

    it('saveTo falha com AzureBlobError em destino inválido', async () => {
        await expect(new BlobContent({ contents: Buffer.from('x'), name: 'x' }).saveTo('/inexistente/x')).rejects.toThrow(
            AzureBlobError,
        );
    });
});

describe('erros', () => {
    it('carregam status, código e contexto', () => {
        const notFound = BlobNotFoundError.make('a.txt', 'docs');

        expect(notFound).toBeInstanceOf(AzureBlobError);
        expect(notFound.status).toBe(404);
        expect(notFound.errorCode).toBe('BlobNotFound');
        expect(notFound.name).toBe('BlobNotFoundError');
        expect(BlobNotFoundError.make('', 'docs').message).toBe('azure-blob: container "docs" não encontrado.');
        expect(BlobNotFoundError.make('', 'docs').errorCode).toBe('ContainerNotFound');
        expect(BlobNotFoundError.make('a', 'docs', 'ContainerNotFound').errorCode).toBe('ContainerNotFound');
        expect(BlobTooLargeError.make('a', 10, 5).context).toEqual({ blob: 'a', size: 10, max_download_size: 5 });
        expect(ReadOnlyError.for('x', 'upload').message).toContain('"upload" bloqueada');
        expect(ConfigurationError.unknownConnection('x').status).toBeNull();
        expect(ConfigurationError.signingUnavailable('x').errorCode).toBeNull();
    });
});
