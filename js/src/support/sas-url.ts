import { isIP } from 'node:net';

/**
 * Decompõe uma SAS URL em endpoint da conta, container e token.
 *
 * A URL sozinha carrega tudo que o SDK precisa para operar, por isso é o modo
 * de autenticação preferido: dispensa nome de conta, chave e container.
 */

export interface ParsedSasUrl {
    accountUrl: string;
    accountName: string;
    container: string;
    sasToken: string;
}

/** `null` quando a URL não tem o formato esperado. */
export function parseSasUrl(sasUrl: string): ParsedSasUrl | null {
    const trimmed = sasUrl.trim();
    const questionMark = trimmed.indexOf('?');

    // O token é recortado da string original em vez de vir de URL.search: o
    // parser WHATWG reencoda caracteres da query, e reencodar a assinatura
    // (`sig`) invalidaria o SAS.
    const sasToken = questionMark === -1 ? '' : trimmed.slice(questionMark + 1).replace(/^\?+/, '');

    let parsed: URL;

    try {
        parsed = new URL(trimmed);
    } catch {
        return null;
    }

    if (parsed.protocol === '' || parsed.hostname === '' || sasToken === '') {
        return null;
    }

    let accountUrl = `${parsed.protocol}//${parsed.host}`;
    const segments = parsed.pathname.split('/').filter((segment) => segment !== '').map(decodeURIComponent);

    let accountName: string;

    // O emulador coloca a conta no primeiro segmento do path
    // (http://127.0.0.1:10000/devstoreaccount1/container). Fora dele, o
    // primeiro segmento já é o container.
    if (hostCarriesAccount(parsed.hostname)) {
        accountName = parsed.hostname.split('.')[0] ?? '';
    } else {
        accountName = segments.shift() ?? '';
        accountUrl += accountName === '' ? '' : `/${accountName}`;
    }

    return {
        accountUrl,
        accountName,
        container: segments[0] ?? '',
        sasToken,
    };
}

/**
 * True quando o subdomínio identifica a conta, como em
 * `conta.blob.core.windows.net`.
 *
 * Um IP ou `localhost` significa emulador (Azurite), onde a conta é o primeiro
 * segmento do path — e um IP tem pontos, então testar por ponto não basta.
 */
function hostCarriesAccount(hostname: string): boolean {
    const bare = hostname.replace(/^\[|\]$/g, '');

    if (isIP(bare) !== 0) {
        return false;
    }

    return bare.includes('.');
}
