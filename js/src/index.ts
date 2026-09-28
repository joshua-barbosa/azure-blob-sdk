export { BlobClient } from './blob-client.js';
export type { CopyOptions, DownloadOptions, ListOptions } from './blob-client.js';
export { BlobManager } from './blob-manager.js';
export type { ManagerOptions } from './blob-manager.js';
export { AUTH_LABELS, Config } from './config.js';
export type {
    AuthMode,
    BlobLogger,
    ConfigData,
    ConnectionOptions,
    FetchFunction,
    HttpOptions,
    SharedOptions,
} from './config.js';
export { API_VERSION, MAX_PAGE, MAX_SINGLE_PUT } from './constants.js';
export { connectionFromEnv, envPrefix, optionsFromEnv } from './env.js';
export type { Env } from './env.js';
export {
    AzureBlobError,
    BlobNotFoundError,
    BlobTooLargeError,
    ConfigurationError,
    ReadOnlyError,
} from './errors.js';
export type { ErrorContext } from './errors.js';
export { BlobContent } from './results/blob-content.js';
export type { Encoding } from './results/blob-content.js';
export { BlobItem } from './results/blob-item.js';
export { BlobList } from './results/blob-list.js';
export { BlobProperties } from './results/blob-properties.js';
export { humanSize } from './results/format.js';
export { guessMimeType } from './support/mime-type.js';
export { basename, directoryPrefix, dirname, encode, normalize } from './support/path.js';
export { scrub, scrubUrl } from './support/redactor.js';
export { SasBuilder, normalizePermissions } from './support/sas-builder.js';
export type { Expiry, SasOptions } from './support/sas-builder.js';
export { SharedKeySigner } from './support/shared-key-signer.js';
export type { UploadContents, UploadOptions } from './support/uploader.js';

import { BlobManager, type ManagerOptions } from './blob-manager.js';

/** Atalho para `new BlobManager(options)`; sem opções, lê o ambiente. */
export function createAzureBlob(options?: ManagerOptions): BlobManager {
    return options === undefined ? BlobManager.fromEnv() : new BlobManager(options);
}
