import { Readable } from 'node:stream';
import { MAX_BLOCKS } from '../constants.js';
import { AzureBlobError } from '../errors.js';
import { guessMimeType } from './mime-type.js';
import { encode } from './path.js';
import type { RestClient } from './rest-client.js';
import type { HeaderMap } from './shared-key-signer.js';
import { blockListXml } from './xml.js';

/**
 * Envio de block blobs.
 *
 * Conteúdo pequeno vai num único `Put Blob`. Acima de `blockSize` o envio é
 * fatiado em `Put Block` + `Put Block List`, o que mantém o uso de memória
 * constante e contorna o teto de 256 MiB do PUT simples.
 */

export type UploadContents =
    | string
    | Uint8Array
    | ArrayBuffer
    | Readable
    | ReadableStream<Uint8Array>
    | AsyncIterable<Uint8Array | string>;

export interface UploadOptions {
    contentType?: string;
    /** `false` faz o Azure devolver 409 em vez de sobrescrever um blob existente. */
    overwrite?: boolean;
    metadata?: Readonly<Record<string, string | number | boolean>>;
    cacheControl?: string;
    contentDisposition?: string;
    contentEncoding?: string;
    contentLanguage?: string;
}

export class Uploader {
    constructor(private readonly client: RestClient) {}

    /** @returns URL do blob enviado. */
    async upload(path: string, contents: UploadContents, options: UploadOptions = {}): Promise<string> {
        const blockSize = this.client.config.blockSize;
        const bytes = toBytes(contents);

        if (bytes !== null && bytes.length <= blockSize) {
            await this.uploadSingle(path, bytes, options);
        } else {
            const reader = new ChunkReader(bytes === null ? toIterable(contents) : [bytes]);

            try {
                // Lê o primeiro bloco e espia o segundo para decidir entre PUT
                // simples e blocos sem precisar do tamanho total, que um stream
                // pode não expor.
                const first = await reader.read(blockSize);
                const second = await reader.read(blockSize);

                if (second.length === 0) {
                    await this.uploadSingle(path, first, options);
                } else {
                    const blockIds = await this.stageBlocks(path, reader, [first, second]);
                    await this.commitBlocks(path, blockIds, options);
                }
            } finally {
                // Numa falha no meio (403, 409, timeout) a fonte ainda estaria
                // aberta: encerrar o iterador destrói o stream e libera o arquivo.
                await reader.close();
            }
        }

        return `${this.client.config.accountUrl}/${encode(path)}`;
    }

    private async uploadSingle(path: string, contents: Buffer, options: UploadOptions): Promise<void> {
        const headers: HeaderMap = {
            'x-ms-blob-type': 'BlockBlob',
            'Content-Type': contentType(options, path),
            ...blobContentHeaders(options),
            ...metadataHeaders(options.metadata),
            ...conditionalHeaders(options),
        };

        await this.client.request('PUT', path, { headers, body: contents });
    }

    /** Envia cada pedaço como um bloco não commitado; devolve os ids, na ordem. */
    private async stageBlocks(path: string, reader: ChunkReader, head: Buffer[]): Promise<string[]> {
        const blockSize = this.client.config.blockSize;
        const blockIds: string[] = [];
        const pending = [...head];
        let chunk = pending.shift() ?? (await reader.read(blockSize));

        while (chunk.length > 0) {
            if (blockIds.length >= MAX_BLOCKS) {
                throw new AzureBlobError(
                    `azure-blob: "${path}" excede ${MAX_BLOCKS} blocos. Aumente "blockSize" na conexão.`,
                    { context: { blob: path, block_size: blockSize } },
                );
            }

            const blockId = Buffer.from(`block-${String(blockIds.length).padStart(8, '0')}`).toString('base64');

            await this.client.request('PUT', path, {
                query: { comp: 'block', blockid: blockId },
                headers: { 'Content-Type': 'application/octet-stream' },
                body: chunk,
            });

            blockIds.push(blockId);
            chunk = pending.shift() ?? (await reader.read(blockSize));
        }

        return blockIds;
    }

    /** Commita a lista de blocos — é este passo que faz o blob existir. */
    private async commitBlocks(path: string, blockIds: string[], options: UploadOptions): Promise<void> {
        const headers: HeaderMap = {
            'Content-Type': 'application/xml',
            'x-ms-blob-content-type': contentType(options, path),
            ...blobContentHeaders(options),
            ...metadataHeaders(options.metadata),
            ...conditionalHeaders(options),
        };

        await this.client.request('PUT', path, {
            query: { comp: 'blocklist' },
            headers,
            body: Buffer.from(blockListXml(blockIds), 'utf8'),
        });
    }
}

/**
 * Lê de uma fonte assíncrona em pedaços de tamanho fixo.
 *
 * Streams entregam pedaços do tamanho que quiserem; os blocos do Azure
 * precisam ter o tamanho configurado (exceto o último).
 */
class ChunkReader {
    private readonly iterator: AsyncIterator<Uint8Array | string> | Iterator<Uint8Array | string>;
    private buffered: Buffer[] = [];
    private bufferedLength = 0;
    private done = false;

    constructor(source: AsyncIterable<Uint8Array | string> | Iterable<Uint8Array | string>) {
        this.iterator =
            Symbol.asyncIterator in source
                ? (source as AsyncIterable<Uint8Array | string>)[Symbol.asyncIterator]()
                : (source as Iterable<Uint8Array | string>)[Symbol.iterator]();
    }

    /** Encerra a fonte antes do fim, quando o upload é interrompido. */
    async close(): Promise<void> {
        if (!this.done) {
            this.done = true;
            await this.iterator.return?.();
        }
    }

    /** Até `length` bytes; menos só no fim da fonte. */
    async read(length: number): Promise<Buffer> {
        while (this.bufferedLength < length && !this.done) {
            const next = await this.iterator.next();

            if (next.done === true) {
                this.done = true;
                break;
            }

            const chunk = typeof next.value === 'string' ? Buffer.from(next.value, 'utf8') : Buffer.from(next.value);
            this.buffered.push(chunk);
            this.bufferedLength += chunk.length;
        }

        const all = Buffer.concat(this.buffered, this.bufferedLength);
        const result = all.subarray(0, length);
        const rest = all.subarray(length);

        this.buffered = rest.length === 0 ? [] : [rest];
        this.bufferedLength = rest.length;

        return result;
    }
}

function toBytes(contents: UploadContents): Buffer | null {
    if (typeof contents === 'string') {
        return Buffer.from(contents, 'utf8');
    }

    if (contents instanceof ArrayBuffer) {
        return Buffer.from(contents);
    }

    if (contents instanceof Uint8Array) {
        return Buffer.isBuffer(contents) ? contents : Buffer.from(contents.buffer, contents.byteOffset, contents.byteLength);
    }

    return null;
}

function toIterable(contents: UploadContents): AsyncIterable<Uint8Array | string> {
    if (typeof ReadableStream !== 'undefined' && contents instanceof ReadableStream) {
        return Readable.fromWeb(contents as import('node:stream/web').ReadableStream<Uint8Array>);
    }

    if (contents !== null && typeof contents === 'object' && Symbol.asyncIterator in contents) {
        return contents as AsyncIterable<Uint8Array | string>;
    }

    throw new AzureBlobError(
        'azure-blob: conteúdo de upload não suportado. Use string, Buffer/Uint8Array, ArrayBuffer ou um stream.',
    );
}

function contentType(options: UploadOptions, path: string): string {
    const type = options.contentType?.trim() ?? '';

    return type !== '' ? type : guessMimeType(path);
}

/**
 * Propriedades de conteúdo do blob. Vão como `x-ms-blob-*` tanto no `Put Blob`
 * quanto no `Put Block List`: o Azure não aceita `Content-Disposition` padrão
 * como propriedade do blob, e no `Put Block List` os cabeçalhos padrão
 * descreveriam o corpo XML da requisição, não o blob final.
 */
function blobContentHeaders(options: UploadOptions): HeaderMap {
    return pick(options, {
        cacheControl: 'x-ms-blob-cache-control',
        contentDisposition: 'x-ms-blob-content-disposition',
        contentEncoding: 'x-ms-blob-content-encoding',
        contentLanguage: 'x-ms-blob-content-language',
    });
}

/**
 * Nomes de metadado viram identificadores C# no Azure: só letras, dígitos e
 * underscore, sem começar com dígito.
 */
export function metadataHeaders(metadata: UploadOptions['metadata'] | undefined): HeaderMap {
    const headers: HeaderMap = {};

    for (const [name, value] of Object.entries(metadata ?? {})) {
        const clean = name.replace(/[^A-Za-z0-9_]/g, '_');

        if (clean !== '' && !/^\d/.test(clean)) {
            headers[`x-ms-meta-${clean}`] = String(value);
        }
    }

    return headers;
}

/** `overwrite: false` vira `If-None-Match: *`. */
function conditionalHeaders(options: UploadOptions): HeaderMap {
    return options.overwrite === false ? { 'If-None-Match': '*' } : {};
}

function pick(options: UploadOptions, map: Partial<Record<keyof UploadOptions, string>>): HeaderMap {
    const headers: HeaderMap = {};

    for (const [option, header] of Object.entries(map)) {
        const value = options[option as keyof UploadOptions];

        if (typeof value === 'string' && value.trim() !== '' && header !== undefined) {
            headers[header] = value.trim();
        }
    }

    return headers;
}
