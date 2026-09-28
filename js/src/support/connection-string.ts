/**
 * Decompõe uma connection string do Azure Storage.
 *
 * Aceita tanto o formato com `AccountName`/`AccountKey` quanto o formato com
 * `SharedAccessSignature`, e respeita um `BlobEndpoint` explícito — usado por
 * contas com domínio próprio e pelo Azurite.
 */

export interface ParsedConnectionString {
    accountName: string;
    accountKey: string;
    endpoint: string;
    sasToken: string;
}

export function parseConnectionString(
    connectionString: string,
    endpointSuffix = 'core.windows.net',
): ParsedConnectionString {
    const parts = segments(connectionString);

    const accountName = parts.get('accountname') ?? '';
    const accountKey = parts.get('accountkey') ?? '';
    const protocol = parts.get('defaultendpointsprotocol') ?? 'https';
    const suffix = parts.get('endpointsuffix') ?? endpointSuffix;
    let endpoint = parts.get('blobendpoint') ?? '';
    const sasToken = (parts.get('sharedaccesssignature') ?? '').replace(/^\?+/, '');

    // Azurite e emuladores usam BlobEndpoint com a conta no path
    // (http://127.0.0.1:10000/devstoreaccount1); nesse caso o endpoint já é a
    // raiz da conta e não deve ganhar o sufixo de novo.
    if (endpoint === '' && accountName !== '') {
        endpoint = `${protocol}://${accountName}.blob.${suffix}`;
    }

    return {
        accountName,
        accountKey,
        endpoint: endpoint.replace(/\/+$/, ''),
        sasToken,
    };
}

/**
 * Divide `Chave=valor;Chave=valor` preservando `=` interno (a AccountKey é
 * base64 e termina em `=`), com as chaves normalizadas em minúsculas.
 */
function segments(connectionString: string): Map<string, string> {
    const parts = new Map<string, string>();

    for (const segment of connectionString.split(';')) {
        const position = segment.indexOf('=');

        if (position <= 0) {
            continue;
        }

        const key = segment.slice(0, position).trim().toLowerCase();

        if (key !== '') {
            parts.set(key, segment.slice(position + 1).trim());
        }
    }

    return parts;
}
