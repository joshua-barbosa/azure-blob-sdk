import { parseBlobList } from '../support/xml.js';
import { BlobItem } from './blob-item.js';

export interface BlobListData {
    items?: readonly BlobItem[];
    directories?: readonly BlobItem[];
    nextMarker?: string | null;
    prefix?: string;
    container?: string;
}

/**
 * Resultado de uma listagem, com o marcador de continuação do Azure.
 *
 * O Azure pagina em no máximo 5.000 itens por resposta. `nextMarker` guarda o
 * ponto de retomada; `BlobClient.listAll()` usa isso para varrer tudo.
 */
export class BlobList implements Iterable<BlobItem> {
    readonly items: readonly BlobItem[];
    readonly directories: readonly BlobItem[];
    readonly nextMarker: string | null;
    readonly prefix: string;
    readonly container: string;

    constructor(data: BlobListData = {}) {
        this.items = Object.freeze([...(data.items ?? [])]);
        this.directories = Object.freeze([...(data.directories ?? [])]);
        this.nextMarker = data.nextMarker ?? null;
        this.prefix = data.prefix ?? '';
        this.container = data.container ?? '';
        Object.freeze(this);
    }

    /** Constrói a partir do XML de `List Blobs`. */
    static fromXml(xml: string, containerUrl: string, container = ''): BlobList {
        const parsed = parseBlobList(xml);

        return new BlobList({
            items: parsed.blobs.map((node) => BlobItem.fromXml(node, containerUrl)),
            directories: parsed.prefixes.map((prefix) => BlobItem.directory(prefix, containerUrl)),
            nextMarker: parsed.nextMarker === '' ? null : parsed.nextMarker,
            prefix: parsed.prefix,
            container: container !== '' ? container : parsed.containerName,
        });
    }

    /** Diretórios e blobs juntos. */
    all(): BlobItem[] {
        return [...this.directories, ...this.items];
    }

    names(): string[] {
        return this.items.map((item) => item.name);
    }

    /** Soma dos tamanhos dos blobs desta página. */
    totalSize(): number {
        return this.items.reduce((total, item) => total + item.size, 0);
    }

    hasMore(): boolean {
        return this.nextMarker !== null;
    }

    isEmpty(): boolean {
        return this.items.length === 0 && this.directories.length === 0;
    }

    get length(): number {
        return this.items.length;
    }

    [Symbol.iterator](): Iterator<BlobItem> {
        return this.items[Symbol.iterator]();
    }

    /** Mesmo formato do `toArray()` do SDK PHP. */
    toJSON(): Record<string, unknown> {
        return {
            container: this.container,
            prefix: this.prefix,
            count: this.items.length,
            total_size: this.totalSize(),
            next_marker: this.nextMarker,
            directories: this.directories.map((item) => item.toJSON()),
            blobs: this.items.map((item) => item.toJSON()),
        };
    }
}
