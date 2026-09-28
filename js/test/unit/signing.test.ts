import { createHmac } from 'node:crypto';
import { describe, expect, it } from 'vitest';
import { SasBuilder, formatSasDate, normalizePermissions, resolveExpiry } from '../../src/support/sas-builder.js';
import { SharedKeySigner, httpDate } from '../../src/support/shared-key-signer.js';

const ACCOUNT = 'contateste';
const KEY = 'Y2hhdmUtZGUtdGVzdGUtc2VjcmV0YS0xMjM0NTY3ODkw';
const DATE = 'Tue, 06 Jan 2026 12:00:00 GMT';
const signer = new SharedKeySigner(ACCOUNT, KEY);
const builder = new SasBuilder(ACCOUNT, KEY, '2022-11-02');

function hmac(payload: string): string {
    return createHmac('sha256', Buffer.from(KEY, 'base64')).update(payload).digest('base64');
}

/**
 * Os valores esperados abaixo foram gerados pelo SDK PHP com as mesmas
 * entradas. Se as duas linguagens divergirem em um byte, estes testes quebram.
 */
describe('paridade com o SDK PHP', () => {
    it('Shared Key produz a mesma assinatura', () => {
        const signed = signer.sign(
            'PUT',
            'https://contateste.blob.core.windows.net/meu-container/pasta%20x/a%C3%A7%C3%A3o.pdf?comp=block&blockid=YmxvY2stMDAwMDAwMDA%3D',
            {
                'x-ms-date': DATE,
                'x-ms-version': '2022-11-02',
                'x-ms-blob-type': 'BlockBlob',
                'Content-Type': 'application/pdf',
                'Content-Length': '17',
                'X-MS-Meta-Origem': ' a  b ',
            },
        );

        expect(signed.Authorization).toBe('SharedKey contateste:ExmR8GbIdSgBe+IFOrH7nsDrdk+cApYJNtE3NI91S6E=');
    });

    it('SAS de blob produz o mesmo token', () => {
        const token = builder.forBlob('meu-container', 'pasta/ação.pdf', new Date('2026-06-01T12:00:00Z'), 'wr', {
            start: new Date('2026-01-01T00:00:00Z'),
            ip: '10.0.0.1',
        });

        expect(token).toBe(
            'sv=2022-11-02&sr=b&sp=rw&se=2026-06-01T12%3A00%3A00Z&sig=A227LnvoVVUJdCJWLyABnKJu0reO6W6vfqR%2F2PeOIGA%3D' +
                '&st=2026-01-01T00%3A00%3A00Z&sip=10.0.0.1',
        );
    });

    it('SAS de container produz o mesmo token', () => {
        const token = builder.forContainer('meu-container', new Date('2026-06-01T12:00:00Z'), 'lr');

        expect(token).toBe(
            'sv=2022-11-02&sr=c&sp=rl&se=2026-06-01T12%3A00%3A00Z&sig=rT%2B3s9%2BLvhJZ%2FTj28vHo77wWWsVrNmrnE3x2KLxwhzw%3D',
        );
    });
});

describe('SharedKeySigner', () => {
    it('monta o string-to-sign no formato documentado', () => {
        const stringToSign = signer.stringToSign(
            'GET',
            'https://contateste.blob.core.windows.net/container/pasta/a.txt',
            { 'x-ms-date': DATE, 'x-ms-version': '2022-11-02' },
        );

        expect(stringToSign).toBe(
            [
                'GET',
                ...Array(11).fill(''),
                `x-ms-date:${DATE}`,
                'x-ms-version:2022-11-02',
                '/contateste/container/pasta/a.txt',
            ].join('\n'),
        );
    });

    it('ordena e normaliza os cabeçalhos x-ms', () => {
        const stringToSign = signer.stringToSign('PUT', 'https://contateste.blob.core.windows.net/c/b', {
            'x-ms-version': '2022-11-02',
            'X-MS-Meta-Origem': '  valor    com   espacos  ',
            'x-ms-date': DATE,
        });

        expect(stringToSign).toContain(
            `x-ms-date:${DATE}\nx-ms-meta-origem:valor com espacos\nx-ms-version:2022-11-02\n`,
        );
    });

    it('Content-Length zero entra como string vazia; diferente de zero é assinado', () => {
        const zero = signer.stringToSign('PUT', 'https://x.blob.core.windows.net/c/b', { 'Content-Length': '0' });
        const filled = signer.stringToSign('PUT', 'https://x.blob.core.windows.net/c/b', {
            'Content-Length': '17',
            'Content-Type': 'text/plain',
        });

        expect(zero.split('\n')[3]).toBe('');
        expect(filled.split('\n')[3]).toBe('17');
        expect(filled.split('\n')[5]).toBe('text/plain');
    });

    it('recurso canônico ordena, minuscula, decodifica e junta valores repetidos', () => {
        expect(
            signer.stringToSign(
                'GET',
                'https://contateste.blob.core.windows.net/container?restype=container&COMP=list&maxresults=50',
                {},
            ),
        ).toMatch(/\/contateste\/container\ncomp:list\nmaxresults:50\nrestype:container$/);

        expect(
            signer.stringToSign('GET', 'https://contateste.blob.core.windows.net/c?include=snapshots&include=metadata', {}),
        ).toMatch(/\/contateste\/c\ninclude:metadata,snapshots$/);

        expect(
            signer.stringToSign(
                'PUT',
                'https://contateste.blob.core.windows.net/c/b?comp=block&blockid=YmxvY2stMDAwMDAwMDA%3D',
                {},
            ),
        ).toMatch(/blockid:YmxvY2stMDAwMDAwMDA=\ncomp:block$/);
    });

    it('URL sem path assina a raiz da conta', () => {
        expect(signer.stringToSign('GET', 'https://contateste.blob.core.windows.net', {})).toMatch(/\/contateste\/$/);
    });

    it('a assinatura confere com o HMAC calculado à parte', () => {
        const headers = { 'x-ms-date': DATE, 'x-ms-version': '2022-11-02' };
        const url = 'https://contateste.blob.core.windows.net/container/a.txt';

        expect(signer.sign('get', url, headers).Authorization).toBe(
            `SharedKey ${ACCOUNT}:${hmac(signer.stringToSign('GET', url, headers))}`,
        );
    });

    it('preenche x-ms-date e x-ms-version sem sobrescrever os informados', () => {
        const filled = signer.sign('GET', 'https://x.blob.core.windows.net/c/b');
        const kept = signer.sign('GET', 'https://x.blob.core.windows.net/c/b', { 'x-ms-version': '2019-12-12' });

        expect(filled['x-ms-version']).toBe('2022-11-02');
        expect(filled['x-ms-date']).toMatch(/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/);
        expect(kept['x-ms-version']).toBe('2019-12-12');
        expect(httpDate(new Date('2026-01-06T12:00:00Z'))).toBe(DATE);
    });
});

describe('SasBuilder', () => {
    const query = (token: string): URLSearchParams => new URLSearchParams(token);

    it('assinatura confere com o string-to-sign documentado', () => {
        const token = query(builder.forBlob('meu-container', 'pasta/a.pdf', new Date('2026-06-01T12:00:00Z'), 'rw'));
        const expected = [
            'rw',
            '',
            '2026-06-01T12:00:00Z',
            '/blob/contateste/meu-container/pasta/a.pdf',
            '',
            '',
            '',
            '2022-11-02',
            'b',
            ...Array(7).fill(''),
        ].join('\n');

        expect(token.get('sig')).toBe(hmac(expected));
        expect(token.get('sr')).toBe('b');
        expect(token.has('st')).toBe(false);
    });

    it('inclui rscd e rsct quando pedidos, dentro da assinatura', () => {
        const token = query(
            builder.forBlob('c', 'a.pdf', new Date('2026-06-01T12:00:00Z'), 'r', {
                contentDisposition: 'attachment; filename="a.pdf"',
                contentType: 'application/pdf',
            }),
        );

        expect(token.get('rscd')).toBe('attachment; filename="a.pdf"');
        expect(token.get('rsct')).toBe('application/pdf');
        expect(token.get('sig')).toBe(
            hmac(
                [
                    'r',
                    '',
                    '2026-06-01T12:00:00Z',
                    '/blob/contateste/c/a.pdf',
                    '',
                    '',
                    '',
                    '2022-11-02',
                    'b',
                    '',
                    '',
                    '',
                    'attachment; filename="a.pdf"',
                    '',
                    '',
                    'application/pdf',
                ].join('\n'),
            ),
        );
    });

    it('horas viram expiração a partir de agora, com piso de uma hora', () => {
        const now = new Date('2026-01-01T00:00:00Z');

        expect(formatSasDate(resolveExpiry(3, now))).toBe('2026-01-01T03:00:00Z');
        expect(formatSasDate(resolveExpiry(0, now))).toBe('2026-01-01T01:00:00Z');
        expect(formatSasDate(resolveExpiry(Number.NaN, now))).toBe('2026-01-01T01:00:00Z');

        const se = new Date(query(builder.forBlob('c', 'a.txt', 2)).get('se') as string).getTime();
        expect(se - Date.now()).toBeGreaterThan(2 * 3600_000 - 60_000);
    });

    it('barras extras no container e no blob não afetam a assinatura', () => {
        const expiry = new Date('2026-06-01T12:00:00Z');

        expect(builder.forBlob('/meu-container/', '/a.txt', expiry)).toBe(builder.forBlob('meu-container', 'a.txt', expiry));
    });

    it('permissões são reordenadas, deduplicadas e filtradas', () => {
        expect(normalizePermissions('wr')).toBe('rw');
        expect(normalizePermissions('dwcar')).toBe('racwd');
        expect(normalizePermissions('rrwwZZ')).toBe('rw');
        expect(normalizePermissions('RW')).toBe('rw');
        expect(normalizePermissions('???')).toBe('r');
        expect(normalizePermissions('')).toBe('r');
    });
});
