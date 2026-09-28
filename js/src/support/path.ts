import { AzureBlobError } from '../errors.js';

/**
 * Normalização de nomes de blob.
 *
 * O Azure trata o nome do blob como uma string opaca — "pastas" são só barras
 * dentro do nome. O que precisa de cuidado é o percent-encoding: cada segmento
 * é codificado, mas as barras que os separam não.
 */

/**
 * Percent-encoding no estilo RFC 3986 (equivalente ao `rawurlencode` do PHP).
 *
 * `encodeURIComponent` deixa `!'()*` de fora; codificá-los também mantém a URL
 * — e portanto a assinatura Shared Key — idêntica à que o SDK PHP produz.
 */
export function rawEncode(value: string): string {
    return encodeURIComponent(value).replace(
        /[!'()*]/g,
        (character) => `%${character.charCodeAt(0).toString(16).toUpperCase()}`,
    );
}

/**
 * Remove barras iniciais e colapsa barras duplicadas.
 *
 * `/pasta//arquivo.txt` e `pasta/arquivo.txt` devem apontar para o mesmo
 * blob; sem isso o Azure criaria dois blobs distintos.
 */
export function normalize(blob: string): string {
    return blob.trim().replaceAll('\\', '/').replace(/\/+/g, '/').replace(/^\/+/, '');
}

/**
 * Recusa nomes com segmentos `.` ou `..`.
 *
 * O parser de URL (usado pelo `fetch`) resolve esses segmentos — mesmo
 * codificados como `%2E` —, então `a/../b` iria parar em `b`. Com um prefixo
 * montado a partir de entrada do usuário, isso escaparia do prefixo.
 */
export function assertSafeName(blob: string): string {
    if (blob.split('/').some((segment) => segment === '.' || segment === '..')) {
        throw new AzureBlobError(`azure-blob: nome de blob inválido "${blob}": segmentos "." e ".." não são permitidos.`, {
            context: { blob },
        });
    }

    return blob;
}

/** Codifica cada segmento sem normalizar — para nomes vindos do próprio Azure. */
export function encodeRaw(blob: string): string {
    return blob.split('/').map(rawEncode).join('/');
}

/** Codifica um nome de blob para uso em URL, preservando as barras. */
export function encode(blob: string): string {
    return normalize(blob).split('/').map(rawEncode).join('/');
}

/** Prefixo de "diretório", sempre terminado em barra (ou vazio na raiz). */
export function directoryPrefix(path: string): string {
    const trimmed = normalize(path).replace(/\/+$/, '');

    return trimmed === '' ? '' : `${trimmed}/`;
}

/** Nome do arquivo, sem o caminho. */
export function basename(blob: string): string {
    const normalized = normalize(blob);
    const position = normalized.lastIndexOf('/');

    return position === -1 ? normalized : normalized.slice(position + 1);
}

/** Caminho do "diretório" que contém o blob, sem barra final. */
export function dirname(blob: string): string {
    const normalized = normalize(blob);
    const position = normalized.lastIndexOf('/');

    return position === -1 ? '' : normalized.slice(0, position);
}
