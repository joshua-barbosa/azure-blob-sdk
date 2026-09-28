/**
 * Versão da REST API usada nos cabeçalhos `x-ms-version` e na assinatura de
 * SAS. 2020-12-06+ é o que define o layout do string-to-sign em SasBuilder.
 */
export const API_VERSION = '2022-11-02';

/** Teto do PUT simples imposto pelo Azure para block blob (256 MiB). */
export const MAX_SINGLE_PUT = 256 * 1024 * 1024;

/** Página máxima aceita pelo Azure em `List Blobs`. */
export const MAX_PAGE = 5000;

/** Nº máximo de blocos por blob, imposto pelo Azure. */
export const MAX_BLOCKS = 50_000;

export const DEFAULTS = Object.freeze({
    endpointSuffix: 'core.windows.net',
    readonly: false,
    maxDownloadSize: 5 * 1024 * 1024,
    blockSize: 4 * 1024 * 1024,
    apiVersion: API_VERSION,
    timeout: 60,
});
