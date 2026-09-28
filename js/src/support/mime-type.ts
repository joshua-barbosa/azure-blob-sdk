import { basename } from './path.js';

/**
 * Descoberta do Content-Type a partir da extensão.
 *
 * Sem isso o Azure grava tudo como `application/octet-stream` e o navegador
 * baixa o arquivo em vez de exibi-lo. A tabela cobre o caso comum; para outro
 * tipo, informe `contentType` no upload.
 */

export const DEFAULT_MIME_TYPE = 'application/octet-stream';

const TYPES: Readonly<Record<string, string>> = {
    txt: 'text/plain',
    csv: 'text/csv',
    html: 'text/html',
    htm: 'text/html',
    css: 'text/css',
    md: 'text/markdown',
    js: 'text/javascript',
    mjs: 'text/javascript',
    json: 'application/json',
    xml: 'application/xml',
    yml: 'application/yaml',
    yaml: 'application/yaml',
    pdf: 'application/pdf',
    zip: 'application/zip',
    gz: 'application/gzip',
    tar: 'application/x-tar',
    doc: 'application/msword',
    docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    xls: 'application/vnd.ms-excel',
    xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ppt: 'application/vnd.ms-powerpoint',
    pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    odt: 'application/vnd.oasis.opendocument.text',
    ods: 'application/vnd.oasis.opendocument.spreadsheet',
    epub: 'application/epub+zip',
    png: 'image/png',
    jpg: 'image/jpeg',
    jpeg: 'image/jpeg',
    gif: 'image/gif',
    webp: 'image/webp',
    avif: 'image/avif',
    svg: 'image/svg+xml',
    ico: 'image/x-icon',
    mp3: 'audio/mpeg',
    wav: 'audio/wav',
    ogg: 'audio/ogg',
    mp4: 'video/mp4',
    webm: 'video/webm',
    woff: 'font/woff',
    woff2: 'font/woff2',
};

export function guessMimeType(path: string): string {
    const name = basename(path);
    const dot = name.lastIndexOf('.');

    if (dot <= 0 || dot === name.length - 1) {
        return DEFAULT_MIME_TYPE;
    }

    return TYPES[name.slice(dot + 1).toLowerCase()] ?? DEFAULT_MIME_TYPE;
}
