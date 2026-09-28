import { SharedKeySigner } from '../../src/support/shared-key-signer.js';
import { escapeXml } from '../../src/support/xml.js';

/**
 * Azure Blob Storage em memória, servido por um `fetch` falso.
 *
 * Implementa o subconjunto da REST API que o SDK usa e confere a assinatura
 * Shared Key de cada requisição — uma assinatura errada devolve 403, como no
 * Azure de verdade.
 */

export const ACCOUNT = 'contateste';
export const KEY = 'Y2hhdmUtZGUtdGVzdGUtc2VjcmV0YS0xMjM0NTY3ODkw';
export const ACCOUNT_URL = `https://${ACCOUNT}.blob.core.windows.net`;
export const SAS_TOKEN = 'sv=2022-11-02&sr=c&sp=racwdl&se=2099-01-01T00%3A00%3A00Z&sig=abc%2Bdef%3D';

export interface StoredBlob {
    body: Buffer;
    contentType: string;
    metadata: Record<string, string>;
    headers: Record<string, string>;
    lastModified: Date;
}

export interface RecordedRequest {
    method: string;
    url: URL;
    headers: Headers;
    body: Buffer;
}

export class FakeAzure {
    readonly blobs = new Map<string, StoredBlob>();
    /** Containers existentes; os que recebem blobs via put() entram sozinhos. */
    readonly containers = new Set<string>(['docs', 'backup']);
    readonly requests: RecordedRequest[] = [];
    private readonly staged = new Map<string, Buffer>();
    private readonly signer = new SharedKeySigner(ACCOUNT, KEY);
    /** Força uma resposta para a próxima requisição que casar. */
    private overrides: Array<{ match: (request: RecordedRequest) => boolean; response: () => Response }> = [];
    pageSize = 5000;
    copyPending = 0;
    /** Status que uma cópia assume depois de sair de "pending". */
    copyOutcome: 'success' | 'failed' = 'success';

    readonly fetch = async (input: string, init: RequestInit): Promise<Response> => {
        const url = new URL(input);
        const headers = new Headers(init.headers as Record<string, string>);
        const body = init.body ? Buffer.from(init.body as Uint8Array) : Buffer.alloc(0);
        const request = { method: (init.method ?? 'GET').toUpperCase(), url, headers, body };
        this.requests.push(request);

        const override = this.overrides.findIndex((candidate) => candidate.match(request));

        if (override !== -1) {
            const [entry] = this.overrides.splice(override, 1);

            return (entry as { response: () => Response }).response();
        }

        if (!this.authorized(input, request)) {
            return error(403, 'AuthenticationFailed', 'Server failed to authenticate the request.');
        }

        return this.handle(request);
    };

    put(name: string, body: string | Buffer, contentType = 'text/plain', metadata: Record<string, string> = {}): void {
        this.blobs.set(name, {
            body: Buffer.isBuffer(body) ? body : Buffer.from(body),
            contentType,
            metadata,
            headers: {},
            lastModified: new Date('2026-01-06T12:00:00Z'),
        });
    }

    respondOnce(match: (request: RecordedRequest) => boolean, response: () => Response): void {
        this.overrides.push({ match, response });
    }

    private authorized(input: string, request: RecordedRequest): boolean {
        const authorization = request.headers.get('authorization');

        if (authorization === null) {
            return request.url.searchParams.has('sig');
        }

        const headers: Record<string, string> = {};
        request.headers.forEach((value, name) => {
            headers[name] = value;
        });

        const expected = this.signer.sign(request.method, input, headers).Authorization;

        return expected === authorization;
    }

    private handle(request: RecordedRequest): Response {
        const [, container = '', ...rest] = request.url.pathname.split('/');
        const name = rest.map(decodeURIComponent).join('/');
        const key = `${decodeURIComponent(container)}/${name}`;
        const comp = request.url.searchParams.get('comp');

        if (request.url.searchParams.get('restype') === 'container' && comp === 'list') {
            return this.list(decodeURIComponent(container), request.url.searchParams);
        }

        if (request.url.searchParams.get('restype') === 'container' && name === '') {
            return this.container(decodeURIComponent(container), request.method);
        }

        switch (request.method) {
            case 'PUT':
                return this.write(key, comp, request);
            case 'HEAD':
            case 'GET':
                return this.read(key, request.method);
            case 'DELETE':
                return this.blobs.delete(key) ? new Response(null, { status: 202 }) : notFound();
            default:
                return error(400, 'UnsupportedHttpVerb', 'Verbo não suportado pelo fake.');
        }
    }

    private container(name: string, method: string): Response {
        if (method === 'HEAD') {
            return new Response(null, { status: this.containers.has(name) ? 200 : 404 });
        }

        if (this.containers.has(name)) {
            return error(409, 'ContainerAlreadyExists', 'The specified container already exists.');
        }

        this.containers.add(name);

        return new Response(null, { status: 201 });
    }

    private write(key: string, comp: string | null, request: RecordedRequest): Response {
        if (comp === 'block') {
            this.staged.set(`${key}#${request.url.searchParams.get('blockid')}`, request.body);

            return new Response(null, { status: 201 });
        }

        if (comp === 'metadata') {
            const blob = this.blobs.get(key);

            if (blob === undefined) {
                return notFound();
            }

            blob.metadata = metadataOf(request.headers);

            return new Response(null, { status: 200 });
        }

        if (request.headers.get('if-none-match') === '*' && this.blobs.has(key)) {
            return error(409, 'BlobAlreadyExists', 'The specified blob already exists.');
        }

        if (comp === 'blocklist') {
            const ids = [...request.body.toString('utf8').matchAll(/<Latest>([^<]*)<\/Latest>/g)].map((m) => m[1]);
            const body = Buffer.concat(ids.map((id) => this.staged.get(`${key}#${id}`) ?? Buffer.alloc(0)));

            this.store(key, body, request.headers.get('x-ms-blob-content-type') ?? 'application/octet-stream', request);

            return new Response(null, { status: 201 });
        }

        const copySource = request.headers.get('x-ms-copy-source');

        if (copySource !== null) {
            const source = new URL(copySource);
            const [, sourceContainer = '', ...sourceName] = source.pathname.split('/');
            const original = this.blobs.get(`${decodeURIComponent(sourceContainer)}/${sourceName.map(decodeURIComponent).join('/')}`);

            if (original === undefined) {
                return error(404, 'CannotVerifyCopySource', 'The specified blob does not exist.');
            }

            this.blobs.set(key, { ...original, lastModified: new Date() });
            const pending = this.copyPending > 0;

            return new Response(null, {
                status: 202,
                headers: { 'x-ms-copy-status': pending ? 'pending' : 'success' },
            });
        }

        this.store(key, request.body, request.headers.get('content-type') ?? 'application/octet-stream', request);

        return new Response(null, { status: 201 });
    }

    private store(key: string, body: Buffer, contentType: string, request: RecordedRequest): void {
        const headers: Record<string, string> = {};

        for (const name of ['cache-control', 'content-disposition', 'content-encoding', 'content-language']) {
            const value = request.headers.get(name) ?? request.headers.get(`x-ms-blob-${name}`);

            if (value !== null) {
                headers[name] = value;
            }
        }

        this.blobs.set(key, { body, contentType, metadata: metadataOf(request.headers), headers, lastModified: new Date() });
    }

    private read(key: string, method: string): Response {
        const blob = this.blobs.get(key);

        if (blob === undefined) {
            return method === 'HEAD' ? new Response(null, { status: 404, headers: { 'x-ms-error-code': 'BlobNotFound' } }) : notFound();
        }

        let copyStatus: string = this.copyOutcome;

        if (this.copyPending > 0) {
            this.copyPending--;
            copyStatus = 'pending';
        }

        const headers: Record<string, string> = {
            'content-type': blob.contentType,
            'content-length': String(blob.body.length),
            'last-modified': blob.lastModified.toUTCString(),
            etag: '"0x8DC0000000000"',
            'x-ms-blob-type': 'BlockBlob',
            'x-ms-creation-time': 'Tue, 06 Jan 2026 12:00:00 GMT',
            'x-ms-copy-status': copyStatus,
            ...(copyStatus === 'failed' ? { 'x-ms-copy-status-description': '403 AuthorizationFailure' } : {}),
            ...blob.headers,
        };

        for (const [name, value] of Object.entries(blob.metadata)) {
            headers[`x-ms-meta-${name}`] = value;
        }

        return new Response(method === 'HEAD' ? null : new Uint8Array(blob.body), { status: 200, headers });
    }

    private list(container: string, query: URLSearchParams): Response {
        const prefix = query.get('prefix') ?? '';
        const delimiter = query.get('delimiter');
        const max = Math.min(Number(query.get('maxresults') ?? 5000), this.pageSize);
        const start = Number(query.get('marker') ?? 0);

        const names = [...this.blobs.keys()]
            .filter((key) => key.startsWith(`${container}/`))
            .map((key) => key.slice(container.length + 1))
            .filter((name) => name.startsWith(prefix))
            .sort();

        const entries: Array<{ kind: 'blob' | 'prefix'; name: string }> = [];
        const seen = new Set<string>();

        for (const name of names) {
            const rest = name.slice(prefix.length);
            const cut = delimiter === null ? -1 : rest.indexOf(delimiter);

            if (cut === -1) {
                entries.push({ kind: 'blob', name });
            } else {
                const directory = prefix + rest.slice(0, cut + 1);

                if (!seen.has(directory)) {
                    seen.add(directory);
                    entries.push({ kind: 'prefix', name: directory });
                }
            }
        }

        const page = entries.slice(start, start + max);
        const next = start + max < entries.length ? String(start + max) : '';

        const body = page
            .map((entry) => {
                if (entry.kind === 'prefix') {
                    return `<BlobPrefix><Name>${escapeXml(entry.name)}</Name></BlobPrefix>`;
                }

                const blob = this.blobs.get(`${container}/${entry.name}`) as StoredBlob;

                return (
                    `<Blob><Name>${escapeXml(entry.name)}</Name><Properties>` +
                    `<Creation-Time>Tue, 06 Jan 2026 12:00:00 GMT</Creation-Time>` +
                    `<Last-Modified>${blob.lastModified.toUTCString()}</Last-Modified>` +
                    `<Etag>0x8DC0000000000</Etag><Content-Length>${blob.body.length}</Content-Length>` +
                    `<Content-Type>${escapeXml(blob.contentType)}</Content-Type><Content-MD5 />` +
                    `<BlobType>BlockBlob</BlobType></Properties>` +
                    `<Metadata><Blob>armadilha</Blob><Name>armadilha</Name></Metadata></Blob>`
                );
            })
            .join('');

        const xml =
            `<?xml version="1.0" encoding="utf-8"?><EnumerationResults ServiceEndpoint="${ACCOUNT_URL}/" ContainerName="${container}">` +
            `<Prefix>${escapeXml(prefix)}</Prefix><MaxResults>${max}</MaxResults><Blobs>${body}</Blobs>` +
            `<NextMarker>${next}</NextMarker></EnumerationResults>`;

        return new Response(xml, { status: 200, headers: { 'content-type': 'application/xml' } });
    }
}

function metadataOf(headers: Headers): Record<string, string> {
    const metadata: Record<string, string> = {};

    headers.forEach((value, name) => {
        if (name.startsWith('x-ms-meta-')) {
            metadata[name.slice('x-ms-meta-'.length)] = value;
        }
    });

    return metadata;
}

export function error(status: number, code: string, message: string): Response {
    const body =
        `<?xml version="1.0" encoding="utf-8"?><Error><Code>${code}</Code>` +
        `<Message>${message}\nRequestId:0000\nTime:2026-01-06T12:00:00.0000000Z</Message></Error>`;

    return new Response(body, { status, headers: { 'content-type': 'application/xml', 'x-ms-error-code': code } });
}

function notFound(): Response {
    return error(404, 'BlobNotFound', 'The specified blob does not exist.');
}
