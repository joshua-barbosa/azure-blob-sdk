import { describe, expect, it, vi } from 'vitest';
import { AzureBlobError, BlobManager, BlobNotFoundError } from '../../src/index.js';
import { fakeManager } from '../helpers/clients.js';
import { KEY, error } from '../helpers/fake-azure.js';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

describe('ids de rastreio', () => {
    it('toda requisição leva um x-ms-client-request-id próprio, nos dois modos de auth', async () => {
        const { azure, manager } = fakeManager();
        azure.put('docs/a.txt', 'oi');

        await manager.get('a.txt');
        await manager.connection('sas').get('a.txt');

        const ids = azure.requests.map((request) => request.headers.get('x-ms-client-request-id') ?? '');

        expect(ids).toHaveLength(2);
        expect(ids[0]).toMatch(UUID);
        expect(ids[1]).toMatch(UUID);
        expect(ids[0]).not.toBe(ids[1]);
    });

    it('erro carrega request_id e client_request_id no contexto e no log', async () => {
        const warn = vi.fn();
        const { azure, manager } = fakeManager({ logger: { warn } });
        azure.respondOnce(
            () => true,
            () => new Response('', { status: 503, headers: { 'x-ms-request-id': 'req-503', 'x-ms-error-code': 'ServerBusy' } }),
        );

        const failure = (await manager.get('a.txt').catch((caught: unknown) => caught)) as AzureBlobError;

        expect(failure).toBeInstanceOf(AzureBlobError);
        expect(failure.requestId).toBe('req-503');
        expect(failure.clientRequestId).toBe(azure.requests[0]?.headers.get('x-ms-client-request-id'));
        expect(failure.clientRequestId).toMatch(UUID);
        expect(warn).toHaveBeenCalledWith(expect.any(String), expect.objectContaining({ request_id: 'req-503', client_request_id: failure.clientRequestId }));
    });

    it('404 também carrega os ids; sem x-ms-request-id o valor é null', async () => {
        const { azure, manager } = fakeManager();
        azure.respondOnce(
            () => true,
            () => new Response('', { status: 404, headers: { 'x-ms-request-id': 'req-404' } }),
        );

        const missing = (await manager.stream('nada.txt').catch((caught: unknown) => caught)) as AzureBlobError;
        expect(missing).toBeInstanceOf(BlobNotFoundError);
        expect(missing.requestId).toBe('req-404');
        expect(missing.clientRequestId).toMatch(UUID);

        azure.respondOnce(() => true, () => error(500, 'InternalError', 'falhou'));
        const failure = (await manager.get('a.txt').catch((caught: unknown) => caught)) as AzureBlobError;
        expect(failure.requestId).toBeNull();
    });

    it('falha de rede carrega o client_request_id', async () => {
        const manager = new BlobManager({
            connections: { default: { name: 'contateste', key: KEY, container: 'docs' } },
            http: { fetch: () => Promise.reject(new Error('ECONNRESET')) },
        });

        const failure = (await manager.get('a.txt').catch((caught: unknown) => caught)) as AzureBlobError;

        expect(failure.clientRequestId).toMatch(UUID);
        expect(failure.requestId).toBeNull();
    });
});

describe('protocolo do SAS gerado', () => {
    it('endpoint https gera SAS restrito a https', () => {
        const { manager } = fakeManager();

        expect(new URL(manager.temporaryUrl('a.pdf')).searchParams.get('spr')).toBe('https');
        expect(new URL(manager.temporaryContainerUrl()).searchParams.get('spr')).toBe('https');
    });

    it('endpoint http (emulador) não restringe o protocolo', () => {
        const azurite = new BlobManager({
            connections: { default: { name: 'devstoreaccount1', key: KEY, container: 'c', url: 'http://127.0.0.1:10000/devstoreaccount1' } },
        });

        expect(new URL(azurite.temporaryUrl('a.pdf')).searchParams.has('spr')).toBe(false);
    });
});
