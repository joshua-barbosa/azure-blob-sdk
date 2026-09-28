import { atom, humanSize, nullable, parseDate } from './format.js';

export interface BlobPropertiesData {
    name: string;
    container: string;
    size?: number;
    contentType?: string | null;
    contentMd5?: string | null;
    contentEncoding?: string | null;
    cacheControl?: string | null;
    contentDisposition?: string | null;
    lastModified?: Date | null;
    createdOn?: Date | null;
    etag?: string | null;
    blobType?: string | null;
    accessTier?: string | null;
    metadata?: Readonly<Record<string, string>>;
    url?: string;
}

/**
 * Propriedades de um blob, extraídas dos cabeçalhos de `Get Blob Properties`.
 *
 * O Azure responde a um HEAD sem corpo: todo o metadado vem em cabeçalhos, e os
 * definidos pelo usuário aparecem com o prefixo `x-ms-meta-`.
 */
export class BlobProperties {
    readonly name: string;
    readonly container: string;
    readonly size: number;
    readonly contentType: string | null;
    readonly contentMd5: string | null;
    readonly contentEncoding: string | null;
    readonly cacheControl: string | null;
    readonly contentDisposition: string | null;
    readonly lastModified: Date | null;
    readonly createdOn: Date | null;
    readonly etag: string | null;
    readonly blobType: string | null;
    readonly accessTier: string | null;
    readonly metadata: Readonly<Record<string, string>>;
    readonly url: string;

    constructor(data: BlobPropertiesData) {
        this.name = data.name;
        this.container = data.container;
        this.size = data.size ?? 0;
        this.contentType = data.contentType ?? null;
        this.contentMd5 = data.contentMd5 ?? null;
        this.contentEncoding = data.contentEncoding ?? null;
        this.cacheControl = data.cacheControl ?? null;
        this.contentDisposition = data.contentDisposition ?? null;
        this.lastModified = data.lastModified ?? null;
        this.createdOn = data.createdOn ?? null;
        this.etag = data.etag ?? null;
        this.blobType = data.blobType ?? null;
        this.accessTier = data.accessTier ?? null;
        this.metadata = Object.freeze({ ...(data.metadata ?? {}) });
        this.url = data.url ?? '';
        Object.freeze(this);
    }

    static fromHeaders(headers: Headers, name: string, container: string, url: string): BlobProperties {
        const get = (key: string): string | null => nullable(headers.get(key));
        const metadata: Record<string, string> = {};

        headers.forEach((value, header) => {
            if (header.toLowerCase().startsWith('x-ms-meta-')) {
                metadata[header.slice('x-ms-meta-'.length)] = value;
            }
        });

        return new BlobProperties({
            name,
            container,
            size: Number.parseInt(get('content-length') ?? '0', 10) || 0,
            contentType: get('content-type'),
            contentMd5: get('content-md5'),
            contentEncoding: get('content-encoding'),
            cacheControl: get('cache-control'),
            contentDisposition: get('content-disposition'),
            lastModified: parseDate(get('last-modified')),
            createdOn: parseDate(get('x-ms-creation-time')),
            etag: nullable(get('etag')?.replace(/^"|"$/g, '')),
            blobType: get('x-ms-blob-type'),
            accessTier: get('x-ms-access-tier'),
            metadata,
            url,
        });
    }

    humanSize(): string {
        return humanSize(this.size);
    }

    /** Mesmo formato do `toArray()` do SDK PHP. */
    toJSON(): Record<string, unknown> {
        return {
            name: this.name,
            container: this.container,
            size: this.size,
            content_type: this.contentType,
            content_md5: this.contentMd5,
            content_encoding: this.contentEncoding,
            cache_control: this.cacheControl,
            content_disposition: this.contentDisposition,
            last_modified: atom(this.lastModified),
            created_on: atom(this.createdOn),
            etag: this.etag,
            blob_type: this.blobType,
            access_tier: this.accessTier,
            metadata: { ...this.metadata },
            url: this.url,
        };
    }
}
