import type { Readable } from 'node:stream';
import { DriveDirectory, DriveFile } from 'flydrive';
import type {
    DriverContract,
    ObjectMetaData,
    ObjectVisibility,
    SignedURLOptions,
    WriteOptions,
} from 'flydrive/types';
import { BlobClient } from '../blob-client.js';
import { Config, type ConnectionOptions } from '../config.js';
import { AzureBlobError } from '../errors.js';
import type { BlobItem } from '../results/blob-item.js';
import { directoryPrefix, normalize } from '../support/path.js';
import type { UploadOptions } from '../support/uploader.js';

/**
 * Driver do FlyDrive — o Drive do AdonisJS, também usável fora dele.
 *
 *     import { Disk } from 'flydrive';
 *     import { AzureBlobDriver } from '@joshualevy029/azure-blob-sdk/flydrive';
 *
 *     const disk = new Disk(new AzureBlobDriver({ sasUrl: process.env.AZURE_STORAGE_SAS_URL }));
 *     await disk.put('avatars/1.png', bytes);
 *
 * Visibilidade não é implementada de propósito: no Azure o nível de acesso
 * público é do container, não do blob. Fingir suporte por blob esconderia que a
 * chamada não teve efeito — o mesmo critério do adaptador Flysystem do SDK PHP.
 */

export interface AzureBlobDriverOptions {
    /** Prefixo aplicado a todas as chaves, como um "subdiretório raiz" do disco. */
    prefix?: string;
}

const VISIBILITY_UNSUPPORTED = 'O Azure Blob Storage define acesso público no container, não por blob.';

export class AzureBlobDriver implements DriverContract {
    readonly client: BlobClient;
    private readonly prefix: string;

    constructor(client: BlobClient | ConnectionOptions, options: AzureBlobDriverOptions = {}) {
        this.client = client instanceof BlobClient ? client : new BlobClient(Config.fromOptions(client, 'flydrive'));
        this.prefix = directoryPrefix(options.prefix ?? '');
    }

    exists(key: string): Promise<boolean> {
        return this.client.exists(this.key(key));
    }

    async get(key: string): Promise<string> {
        return (await this.client.get(this.key(key))).toString('utf8');
    }

    getStream(key: string): Promise<Readable> {
        return this.client.stream(this.key(key));
    }

    async getBytes(key: string): Promise<Uint8Array> {
        return new Uint8Array(await this.client.get(this.key(key)));
    }

    async getMetaData(key: string): Promise<ObjectMetaData> {
        const properties = await this.client.properties(this.key(key));

        return {
            contentLength: properties.size,
            etag: properties.etag ?? '',
            lastModified: properties.lastModified ?? new Date(0),
            ...(properties.contentType === null ? {} : { contentType: properties.contentType }),
        };
    }

    async getVisibility(_key: string): Promise<ObjectVisibility> {
        throw new AzureBlobError(`azure-blob: ${VISIBILITY_UNSUPPORTED}`);
    }

    async setVisibility(_key: string, _visibility: ObjectVisibility): Promise<void> {
        throw new AzureBlobError(`azure-blob: ${VISIBILITY_UNSUPPORTED}`);
    }

    async getUrl(key: string): Promise<string> {
        return this.client.url(this.key(key));
    }

    async getSignedUrl(key: string, options: SignedURLOptions = {}): Promise<string> {
        return this.client.temporaryUrl(this.key(key), expiresAt(options.expiresIn), 'r', {
            contentType: options.contentType ?? null,
            contentDisposition: options.contentDisposition ?? null,
        });
    }

    /**
     * URL para o navegador enviar direto ao Azure. O `PUT` precisa carregar o
     * cabeçalho `x-ms-blob-type: BlockBlob`.
     */
    async getSignedUploadUrl(key: string, options: SignedURLOptions = {}): Promise<string> {
        return this.client.temporaryUrl(this.key(key), expiresAt(options.expiresIn), 'cw');
    }

    async put(key: string, contents: string | Uint8Array, options: WriteOptions = {}): Promise<void> {
        await this.client.upload(this.key(key), contents, uploadOptions(options));
    }

    async putStream(key: string, contents: Readable, options: WriteOptions = {}): Promise<void> {
        await this.client.upload(this.key(key), contents, uploadOptions(options));
    }

    async copy(source: string, destination: string): Promise<void> {
        await this.client.copy(this.key(source), this.key(destination), { wait: true });
    }

    async move(source: string, destination: string): Promise<void> {
        await this.client.move(this.key(source), this.key(destination));
    }

    /** Apagar o que já não existe não é erro — `delete()` já devolve false. */
    async delete(key: string): Promise<void> {
        await this.client.delete(this.key(key));
    }

    async deleteAll(prefix: string): Promise<void> {
        await this.client.deleteDirectory(this.key(prefix));
    }

    async listAll(
        prefix: string,
        options: { recursive?: boolean; paginationToken?: string } = {},
    ): Promise<{ paginationToken?: string; objects: Iterable<DriveFile | DriveDirectory> }> {
        const page = await this.client.list(this.key(directoryPrefix(prefix)), 1000, {
            delimiter: options.recursive === true ? null : '/',
            marker: options.paginationToken ?? null,
        });

        const objects = [
            ...page.directories.map((directory) => new DriveDirectory(this.unprefixed(directory.name))),
            ...page.items.map((item) => new DriveFile(this.unprefixed(item.name), this, metadata(item))),
        ];

        return page.nextMarker === null ? { objects } : { objects, paginationToken: page.nextMarker };
    }

    bucket(container: string): AzureBlobDriver {
        return new AzureBlobDriver(this.client.container(container), { prefix: this.prefix });
    }

    private key(key: string): string {
        return `${this.prefix}${normalize(key)}`;
    }

    private unprefixed(name: string): string {
        return this.prefix !== '' && name.startsWith(this.prefix) ? name.slice(this.prefix.length) : name;
    }
}

function metadata(item: BlobItem): ObjectMetaData {
    return {
        contentLength: item.size,
        etag: item.etag ?? '',
        lastModified: item.lastModified ?? new Date(0),
        ...(item.contentType === null ? {} : { contentType: item.contentType }),
    };
}

function uploadOptions(options: WriteOptions): UploadOptions {
    if (options.visibility !== undefined) {
        throw new AzureBlobError(`azure-blob: ${VISIBILITY_UNSUPPORTED}`);
    }

    const picked: UploadOptions = {};

    for (const key of ['contentType', 'contentLanguage', 'contentEncoding', 'contentDisposition', 'cacheControl'] as const) {
        const value = options[key];

        if (typeof value === 'string' && value !== '') {
            picked[key] = value;
        }
    }

    if (options['metadata'] !== undefined) {
        picked.metadata = options['metadata'] as UploadOptions['metadata'];
    }

    return picked;
}

const UNITS: Readonly<Record<string, number>> = {
    ms: 0.001,
    s: 1,
    sec: 1,
    secs: 1,
    second: 1,
    seconds: 1,
    m: 60,
    min: 60,
    mins: 60,
    minute: 60,
    minutes: 60,
    h: 3600,
    hr: 3600,
    hrs: 3600,
    hour: 3600,
    hours: 3600,
    d: 86_400,
    day: 86_400,
    days: 86_400,
};

/**
 * `expiresIn` do FlyDrive: segundos (número) ou expressão como "30mins",
 * "2 hours". O padrão, como nos drivers oficiais, é 30 minutos.
 */
export function expiresAt(expiresIn: string | number | undefined, now: Date = new Date()): Date {
    let seconds = 30 * 60;

    if (typeof expiresIn === 'number' && Number.isFinite(expiresIn)) {
        seconds = expiresIn;
    } else if (typeof expiresIn === 'string') {
        const match = /^\s*(\d+(?:\.\d+)?)\s*([a-z]*)\s*$/i.exec(expiresIn);
        const factor = UNITS[(match?.[2] ?? '').toLowerCase() || 's'];

        if (match === null || factor === undefined) {
            throw new AzureBlobError(`azure-blob: expiresIn "${expiresIn}" inválido. Use segundos ou algo como "30mins".`);
        }

        seconds = Number(match[1]) * factor;
    }

    return new Date(now.getTime() + Math.max(1, seconds) * 1000);
}
