import { describe, expect, it } from 'vitest';
import { parseConnectionString } from '../../src/support/connection-string.js';
import { DEFAULT_MIME_TYPE, guessMimeType } from '../../src/support/mime-type.js';
import { basename, directoryPrefix, dirname, encode, normalize, rawEncode } from '../../src/support/path.js';
import { MASK, scrub, scrubUrl } from '../../src/support/redactor.js';
import { parseSasUrl } from '../../src/support/sas-url.js';
import { blockListXml, decodeEntities, parseBlobList, parseError } from '../../src/support/xml.js';

describe('path', () => {
    it('normaliza barras', () => {
        expect(normalize('  /pasta//sub\\arquivo.txt ')).toBe('pasta/sub/arquivo.txt');
        expect(normalize('')).toBe('');
    });

    it('codifica cada segmento, preservando as barras', () => {
        expect(encode('/pasta x/ação (1).pdf')).toBe('pasta%20x/a%C3%A7%C3%A3o%20%281%29.pdf');
        expect(rawEncode("!'()*~")).toBe('%21%27%28%29%2A~');
    });

    it('extrai prefixo, basename e dirname', () => {
        expect(directoryPrefix('a/b')).toBe('a/b/');
        expect(directoryPrefix('/a/b//')).toBe('a/b/');
        expect(directoryPrefix('/')).toBe('');
        expect(basename('a/b/c.txt')).toBe('c.txt');
        expect(basename('c.txt')).toBe('c.txt');
        expect(dirname('a/b/c.txt')).toBe('a/b');
        expect(dirname('c.txt')).toBe('');
    });
});

describe('parseConnectionString', () => {
    it('monta o endpoint a partir de conta e sufixo', () => {
        expect(
            parseConnectionString(
                'DefaultEndpointsProtocol=https;AccountName=conta;AccountKey=abc==;EndpointSuffix=core.chinacloudapi.cn',
            ),
        ).toEqual({
            accountName: 'conta',
            accountKey: 'abc==',
            endpoint: 'https://conta.blob.core.chinacloudapi.cn',
            sasToken: '',
        });
    });

    it('respeita BlobEndpoint (Azurite) e SAS', () => {
        const parsed = parseConnectionString(
            'BlobEndpoint=http://127.0.0.1:10000/devstoreaccount1/;SharedAccessSignature=?sv=1&sig=x;;semvalor;=x',
        );

        expect(parsed.endpoint).toBe('http://127.0.0.1:10000/devstoreaccount1');
        expect(parsed.sasToken).toBe('sv=1&sig=x');
        expect(parsed.accountName).toBe('');
    });
});

describe('parseSasUrl', () => {
    it('extrai conta, container e token sem reencodar a assinatura', () => {
        expect(parseSasUrl('https://conta.blob.core.windows.net/docs?sv=1&sig=a%2Bb%3D')).toEqual({
            accountUrl: 'https://conta.blob.core.windows.net',
            accountName: 'conta',
            container: 'docs',
            sasToken: 'sv=1&sig=a%2Bb%3D',
        });
    });

    it('trata IP e localhost como emulador, com a conta no path', () => {
        expect(parseSasUrl('http://127.0.0.1:10000/devstoreaccount1/docs?sig=x')).toMatchObject({
            accountUrl: 'http://127.0.0.1:10000/devstoreaccount1',
            accountName: 'devstoreaccount1',
            container: 'docs',
        });
        expect(parseSasUrl('http://localhost:10000/devstoreaccount1/docs?sig=x')?.accountName).toBe('devstoreaccount1');
    });

    it('recusa URLs sem token ou malformadas', () => {
        expect(parseSasUrl('https://conta.blob.core.windows.net/docs')).toBeNull();
        expect(parseSasUrl('não é url?sig=x')).toBeNull();
    });
});

describe('redactor', () => {
    it('mascara chaves sensíveis e assinaturas em URLs, recursivamente', () => {
        expect(
            scrub({
                'Account-Key': 'segredo',
                sas_url: 'https://x?sig=abc',
                url: 'https://x/a?sv=1&sig=abc&se=2',
                nested: { authorization: 'SharedKey x:y', ok: 1 },
                list: ['https://x?sig=abc'],
                when: new Date(0),
            }),
        ).toEqual({
            'Account-Key': MASK,
            sas_url: MASK,
            url: `https://x/a?sv=1&sig=${MASK}&se=2`,
            nested: { authorization: MASK, ok: 1 },
            list: [`https://x?sig=${MASK}`],
            when: new Date(0),
        });
        expect(scrubUrl('sem token')).toBe('sem token');
    });
});

describe('mime-type', () => {
    it('adivinha pela extensão', () => {
        expect(guessMimeType('a/b/Relatorio.PDF')).toBe('application/pdf');
        expect(guessMimeType('dados.json')).toBe('application/json');
        expect(guessMimeType('sem-extensao')).toBe(DEFAULT_MIME_TYPE);
        expect(guessMimeType('.oculto')).toBe(DEFAULT_MIME_TYPE);
        expect(guessMimeType('x.desconhecida')).toBe(DEFAULT_MIME_TYPE);
    });
});

describe('xml', () => {
    it('lê listagem com blobs, prefixos, marcador e nomes codificados', () => {
        const parsed = parseBlobList(
            '<?xml version="1.0"?><EnumerationResults ContainerName="docs"><Prefix>a/</Prefix><Blobs>' +
                '<Blob><Name>a/x &amp; y.txt</Name><Properties><Content-Length>10</Content-Length>' +
                '<Content-Type>text/plain</Content-Type></Properties>' +
                '<Metadata><Blob>armadilha</Blob></Metadata></Blob>' +
                '<Blob><Name Encoded="true">a/%EF%BF%BE.txt</Name><Properties /></Blob>' +
                '<BlobPrefix><Name>a/sub/</Name></BlobPrefix>' +
                '</Blobs><NextMarker>2!abc</NextMarker></EnumerationResults>',
        );

        expect(parsed.containerName).toBe('docs');
        expect(parsed.prefix).toBe('a/');
        expect(parsed.nextMarker).toBe('2!abc');
        expect(parsed.blobs.map((blob) => blob.name)).toEqual(['a/x & y.txt', 'a/￾.txt']);
        expect(parsed.blobs[0]?.properties).toEqual({ 'Content-Length': '10', 'Content-Type': 'text/plain' });
        expect(parsed.prefixes).toEqual(['a/sub/']);
    });

    it('recusa corpo vazio ou que não é listagem', () => {
        expect(() => parseBlobList('  ')).toThrow(/vazia/);
        expect(() => parseBlobList('<html/>')).toThrow(/inválido/);
    });

    it('extrai código e primeira linha da mensagem de erro', () => {
        expect(parseError('<Error><Code>AuthenticationFailed</Code><Message>Falhou.\nRequestId:1</Message></Error>')).toEqual({
            code: 'AuthenticationFailed',
            message: 'Falhou.',
        });
        expect(parseError('')).toEqual({ code: null, message: null });
        expect(parseError('<Error></Error>')).toEqual({ code: null, message: null });
    });

    it('decodifica entidades e monta a lista de blocos', () => {
        expect(decodeEntities('&lt;a&gt; &#65;&#x42; &quot;&apos; &desconhecida;')).toBe('<a> AB "\' &desconhecida;');
        expect(blockListXml(['YQ==', 'Yg=='])).toBe(
            '<?xml version="1.0" encoding="utf-8"?>\n<BlockList>\n  <Latest>YQ==</Latest>\n  <Latest>Yg==</Latest>\n</BlockList>',
        );
    });
});
