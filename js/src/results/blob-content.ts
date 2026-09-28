import { isUtf8 } from 'node:buffer';
import { writeFile } from 'node:fs/promises';
import { AzureBlobError } from '../errors.js';
import type { BlobProperties } from './blob-properties.js';
import { humanSize } from './format.js';

export type Encoding = 'text' | 'base64';

/** Tipos MIME tratados como texto além de `text/*`. */
const TEXT_TYPES = new Set([
    'application/json',
    'application/xml',
    'application/javascript',
    'application/x-yaml',
    'application/yaml',
    'application/ld+json',
    'image/svg+xml',
]);

export interface BlobContentData {
    contents: Buffer;
    name: string;
    contentType?: string | null;
    size?: number;
    properties?: BlobProperties | null;
}

/**
 * Conteúdo baixado de um blob.
 *
 * Guarda sempre os bytes crus. `encoding()` diz apenas se o conteúdo é
 * representável como texto UTF-8 — quem precisa transportar o valor em JSON usa
 * `toJSON()`, que aplica base64 no caso binário, como fazia a ferramenta MCP.
 */
export class BlobContent {
    readonly contents: Buffer;
    readonly name: string;
    readonly contentType: string | null;
    readonly size: number;
    readonly properties: BlobProperties | null;

    constructor(data: BlobContentData) {
        this.contents = data.contents;
        this.name = data.name;
        this.contentType = data.contentType ?? null;
        this.size = data.size ?? data.contents.length;
        this.properties = data.properties ?? null;
        Object.freeze(this);
    }

    /** O conteúdo decodificado como UTF-8. */
    text(): string {
        return this.contents.toString('utf8');
    }

    /** Decodifica o conteúdo como JSON. */
    json<T = unknown>(): T {
        try {
            return JSON.parse(this.text()) as T;
        } catch (error) {
            throw new AzureBlobError(
                `azure-blob: blob "${this.name}" não contém JSON válido: ${(error as Error).message}.`,
                { cause: error, context: { blob: this.name, content_type: this.contentType } },
            );
        }
    }

    base64(): string {
        return this.contents.toString('base64');
    }

    /** Grava o conteúdo em disco; devolve os bytes escritos. */
    async saveTo(path: string): Promise<number> {
        try {
            await writeFile(path, this.contents);
        } catch (error) {
            throw new AzureBlobError(`azure-blob: não foi possível gravar "${this.name}" em "${path}".`, {
                cause: error,
                context: { blob: this.name, path },
            });
        }

        return this.contents.length;
    }

    /** `text` quando o conteúdo é UTF-8 válido de um tipo textual, senão `base64`. */
    encoding(): Encoding {
        return this.isText() ? 'text' : 'base64';
    }

    isText(): boolean {
        const type = (this.contentType ?? '').split(';')[0]?.trim().toLowerCase() ?? '';

        const textual =
            type.startsWith('text/') || TEXT_TYPES.has(type) || type.endsWith('+json') || type.endsWith('+xml');

        // Um tipo textual não garante bytes decodificáveis: arquivos rotulados
        // como text/plain aparecem em latin-1 com frequência.
        return textual && isUtf8(this.contents);
    }

    humanSize(): string {
        return humanSize(this.size);
    }

    /** Formato de transporte: texto direto quando possível, base64 caso contrário. */
    toJSON(): Record<string, unknown> {
        const text = this.isText();

        return {
            name: this.name,
            content: text ? this.text() : this.base64(),
            encoding: text ? 'text' : 'base64',
            content_type: this.contentType,
            size: this.size,
        };
    }

    toString(): string {
        return this.text();
    }
}
