import { AzureBlobError } from '../errors.js';

/**
 * Leitura e escrita do XML da REST API do Blob Storage.
 *
 * O Azure não oferece JSON para as operações de blob: listagem, erros e commit
 * de blocos são todos XML. As respostas têm um formato fixo e raso, então um
 * extrator por expressão regular basta — e evita uma dependência de parser.
 * Não há DTD nem entidades externas envolvidas, portanto não há vetor de XXE.
 */

export interface XmlBlob {
    name: string;
    properties: Readonly<Record<string, string>>;
}

export interface XmlBlobList {
    containerName: string;
    prefix: string;
    nextMarker: string;
    blobs: XmlBlob[];
    prefixes: string[];
}

export interface XmlError {
    code: string | null;
    message: string | null;
}

const ENTITIES: Readonly<Record<string, string>> = {
    amp: '&',
    lt: '<',
    gt: '>',
    quot: '"',
    apos: "'",
};

/** Resolve as entidades predefinidas do XML e as referências numéricas. */
export function decodeEntities(value: string): string {
    return value.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (match, entity: string) => {
        if (entity.startsWith('#x') || entity.startsWith('#X')) {
            return String.fromCodePoint(Number.parseInt(entity.slice(2), 16));
        }

        if (entity.startsWith('#')) {
            return String.fromCodePoint(Number.parseInt(entity.slice(1), 10));
        }

        return ENTITIES[entity.toLowerCase()] ?? match;
    });
}

export function escapeXml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&apos;');
}

function tag(xml: string, name: string): string | null {
    const match = new RegExp(`<${name}(?:\\s[^>]*)?>([\\s\\S]*?)</${name}>`).exec(xml);

    return match === null ? null : (match[1] ?? '');
}

/**
 * Nomes com caracteres inválidos em XML chegam percent-encoded, marcados com
 * `Encoded="true"`.
 */
function blobName(xml: string): string {
    const match = /<Name(\s+Encoded="(true|false)")?\s*>([\s\S]*?)<\/Name>/.exec(xml);

    if (match === null) {
        return '';
    }

    const value = decodeEntities(match[3] ?? '');

    return match[2] === 'true' ? decodeURIComponent(value) : value;
}

function properties(xml: string): Record<string, string> {
    const block = tag(xml, 'Properties') ?? '';
    const values: Record<string, string> = {};

    for (const match of block.matchAll(/<([A-Za-z][\w-]*)(?:\s[^>]*)?>([^<]*)<\/\1>/g)) {
        values[match[1] as string] = decodeEntities((match[2] ?? '').trim());
    }

    return values;
}

/** Lê a resposta de `List Blobs`. */
export function parseBlobList(xml: string): XmlBlobList {
    const body = xml.trim();

    if (body === '') {
        throw new AzureBlobError('azure-blob: resposta XML vazia do Azure.');
    }

    const root = /<EnumerationResults\b([^>]*)>/.exec(body);

    if (root === null) {
        throw new AzureBlobError('azure-blob: XML inválido na resposta do Azure.');
    }

    // Metadados e tags podem ter qualquer nome — inclusive "Blob" ou "Name" —
    // e confundiriam a extração. Nenhum dos dois é exposto na listagem.
    const cleaned = body
        .replace(/<Metadata>[\s\S]*?<\/Metadata>/g, '')
        .replace(/<OrMetadata>[\s\S]*?<\/OrMetadata>/g, '')
        .replace(/<Tags>[\s\S]*?<\/Tags>/g, '');

    const blobsSection = tag(cleaned, 'Blobs') ?? '';

    const blobs = [...blobsSection.matchAll(/<Blob>([\s\S]*?)<\/Blob>/g)].map((match) => ({
        name: blobName(match[1] ?? ''),
        properties: properties(match[1] ?? ''),
    }));

    const prefixes = [...blobsSection.matchAll(/<BlobPrefix>([\s\S]*?)<\/BlobPrefix>/g)].map((match) =>
        blobName(match[1] ?? ''),
    );

    const container = /\bContainerName="([^"]*)"/.exec(root[1] ?? '');
    // Prefix e NextMarker ficam fora de <Blobs>; buscar só no cabeçalho e no
    // rodapé evita casar um blob cujo nome contenha o texto da tag.
    const outside = cleaned.replace(/<Blobs>[\s\S]*<\/Blobs>/, '');

    return {
        containerName: decodeEntities(container?.[1] ?? ''),
        prefix: decodeEntities(tag(outside, 'Prefix') ?? ''),
        nextMarker: decodeEntities((tag(outside, 'NextMarker') ?? '').trim()),
        blobs,
        prefixes,
    };
}

/**
 * Extrai `Code` e `Message` de um corpo de erro do Azure.
 *
 * Erros também chegam no cabeçalho `x-ms-error-code`; o corpo é o único lugar
 * com a mensagem legível.
 */
export function parseError(body: string): XmlError {
    if (body.trim() === '' || !body.includes('<Error')) {
        return { code: null, message: null };
    }

    const code = decodeEntities((tag(body, 'Code') ?? '').trim());
    // A mensagem do Azure vem com "\nRequestId:...\nTime:..." anexado.
    const message = decodeEntities((tag(body, 'Message') ?? '').trim()).split('\n')[0]?.trim() ?? '';

    return {
        code: code === '' ? null : code,
        message: message === '' ? null : message,
    };
}

/**
 * Monta o corpo de `Put Block List`.
 *
 * Todos os blocos vão como `Latest`, que resolve para a versão recém-enviada
 * independentemente de existir um bloco commitado com o mesmo id.
 */
export function blockListXml(blockIds: readonly string[]): string {
    const lines = blockIds.map((id) => `  <Latest>${escapeXml(id)}</Latest>`);

    return ['<?xml version="1.0" encoding="utf-8"?>', '<BlockList>', ...lines, '</BlockList>'].join('\n');
}
