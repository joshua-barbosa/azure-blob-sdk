import { basename, dirname, encode } from '../support/path.js';
import type { XmlBlob } from '../support/xml.js';
import { atom, humanSize, nullable, parseDate } from './format.js';

export interface BlobItemData {
    name: string;
    size?: number;
    contentType?: string | null;
    lastModified?: Date | null;
    createdOn?: Date | null;
    etag?: string | null;
    contentMd5?: string | null;
    blobType?: string | null;
    url?: string;
    isDirectory?: boolean;
}

/**
 * Um blob (ou "diretório") devolvido pela listagem. Objeto de valor imutável.
 */
export class BlobItem {
    readonly name: string;
    readonly size: number;
    readonly contentType: string | null;
    readonly lastModified: Date | null;
    readonly createdOn: Date | null;
    readonly etag: string | null;
    readonly contentMd5: string | null;
    readonly blobType: string | null;
    readonly url: string;
    readonly isDirectory: boolean;

    constructor(data: BlobItemData) {
        this.name = data.name;
        this.size = data.size ?? 0;
        this.contentType = data.contentType ?? null;
        this.lastModified = data.lastModified ?? null;
        this.createdOn = data.createdOn ?? null;
        this.etag = data.etag ?? null;
        this.contentMd5 = data.contentMd5 ?? null;
        this.blobType = data.blobType ?? null;
        this.url = data.url ?? '';
        this.isDirectory = data.isDirectory ?? false;
        Object.freeze(this);
    }

    /** Constrói a partir de um `<Blob>` da resposta `List Blobs`. */
    static fromXml(node: XmlBlob, containerUrl: string): BlobItem {
        const property = (key: string): string | null => nullable(node.properties[key]);

        return new BlobItem({
            name: node.name,
            size: Number.parseInt(property('Content-Length') ?? '0', 10) || 0,
            contentType: property('Content-Type'),
            lastModified: parseDate(property('Last-Modified')),
            createdOn: parseDate(property('Creation-Time')),
            etag: nullable(property('Etag')?.replace(/^"|"$/g, '')),
            contentMd5: property('Content-MD5'),
            blobType: property('BlobType'),
            url: `${containerUrl.replace(/\/+$/, '')}/${encode(node.name)}`,
        });
    }

    /**
     * Constrói a partir de um `<BlobPrefix>`, que representa uma "pasta" quando
     * a listagem usa delimitador.
     */
    static directory(prefix: string, containerUrl: string): BlobItem {
        const name = prefix.replace(/\/+$/, '');

        return new BlobItem({
            name,
            url: `${containerUrl.replace(/\/+$/, '')}/${encode(name)}`,
            isDirectory: true,
        });
    }

    basename(): string {
        return basename(this.name);
    }

    dirname(): string {
        return dirname(this.name);
    }

    humanSize(): string {
        return humanSize(this.size);
    }

    /** Mesmo formato do `toArray()` do SDK PHP. */
    toJSON(): Record<string, unknown> {
        return {
            name: this.name,
            size: this.size,
            content_type: this.contentType,
            last_modified: atom(this.lastModified),
            created_on: atom(this.createdOn),
            etag: this.etag,
            content_md5: this.contentMd5,
            blob_type: this.blobType,
            url: this.url,
            is_directory: this.isDirectory,
        };
    }
}
