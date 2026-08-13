<?php

namespace AzureBlob\Support;

/**
 * Decompõe uma SAS URL em endpoint da conta, container e token.
 *
 * A URL sozinha carrega tudo que o SDK precisa para operar, por isso é o modo
 * de autenticação preferido: dispensa nome de conta, chave e container.
 */
final class SasUrl
{
    /**
     * @return array{account_url:string,account_name:string,container:string,sas_token:string}|null
     *                                                                                              `null` quando a URL não tem o formato esperado.
     */
    public static function parse(string $sasUrl): ?array
    {
        $parsed = parse_url(trim($sasUrl));

        if ($parsed === false
            || empty($parsed['scheme'])
            || empty($parsed['host'])
            || empty($parsed['query'])
        ) {
            return null;
        }

        $accountUrl = $parsed['scheme'].'://'.$parsed['host'];

        if (isset($parsed['port'])) {
            $accountUrl .= ':'.$parsed['port'];
        }

        $path = trim($parsed['path'] ?? '', '/');
        $segments = $path === '' ? [] : explode('/', $path);

        // O emulador coloca a conta no primeiro segmento do path
        // (http://127.0.0.1:10000/devstoreaccount1/container). Fora dele, o
        // primeiro segmento já é o container.
        if (self::hostCarriesAccount($parsed['host'])) {
            $accountName = explode('.', $parsed['host'])[0];
        } else {
            $accountName = $segments === [] ? '' : array_shift($segments);
            $accountUrl .= $accountName === '' ? '' : '/'.$accountName;
        }

        return [
            'account_url' => $accountUrl,
            'account_name' => $accountName,
            'container' => $segments[0] ?? '',
            'sas_token' => ltrim($parsed['query'], '?'),
        ];
    }

    /**
     * True quando o subdomínio identifica a conta, como em
     * `conta.blob.core.windows.net`.
     *
     * Um IP ou `localhost` significa emulador (Azurite), onde a conta é o
     * primeiro segmento do path — e um IP tem pontos, então testar por ponto
     * não basta.
     */
    private static function hostCarriesAccount(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return str_contains($host, '.');
    }
}
