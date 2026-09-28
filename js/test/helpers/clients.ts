import { BlobManager, type ManagerOptions } from '../../src/blob-manager.js';
import type { ConnectionOptions } from '../../src/config.js';
import { ACCOUNT, ACCOUNT_URL, FakeAzure, KEY, SAS_TOKEN } from './fake-azure.js';

export const KEY_CONNECTION: ConnectionOptions = { name: ACCOUNT, key: KEY, container: 'docs' };
export const SAS_CONNECTION: ConnectionOptions = { sasUrl: `${ACCOUNT_URL}/docs?${SAS_TOKEN}` };

/** Gerenciador ligado a um Azure falso, com as duas formas de autenticação. */
export function fakeManager(options: ManagerOptions = {}): { azure: FakeAzure; manager: BlobManager } {
    const azure = new FakeAzure();
    const manager = new BlobManager({
        ...options,
        connections: { default: KEY_CONNECTION, sas: SAS_CONNECTION, ...(options.connections ?? {}) },
        http: { fetch: azure.fetch, ...(options.http ?? {}) },
    });

    return { azure, manager };
}
