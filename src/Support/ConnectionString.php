<?php

namespace AzureBlob\Support;

/**
 * Decompõe uma connection string do Azure Storage.
 *
 * Aceita tanto o formato com `AccountName`/`AccountKey` quanto o formato com
 * `SharedAccessSignature`, e respeita um `BlobEndpoint` explícito — usado por
 * contas com domínio próprio e pelo Azurite.
 */
final class ConnectionString
{
    /**
     * @return array{account_name:string,account_key:string,endpoint:string,sas_token:string}
     */
    public static function parse(string $connectionString, string $endpointSuffix = 'core.windows.net'): array
    {
        $parts = self::segments($connectionString);

        $accountName = $parts['accountname'] ?? '';
        $accountKey = $parts['accountkey'] ?? '';
        $protocol = $parts['defaultendpointsprotocol'] ?? 'https';
        $suffix = $parts['endpointsuffix'] ?? $endpointSuffix;
        $endpoint = $parts['blobendpoint'] ?? '';
        $sasToken = ltrim($parts['sharedaccesssignature'] ?? '', '?');

        if ($endpoint === '' && $accountName !== '') {
            $endpoint = sprintf('%s://%s.blob.%s', $protocol, $accountName, $suffix);
        }

        // Azurite e emuladores usam BlobEndpoint com a conta no path
        // (http://127.0.0.1:10000/devstoreaccount1); nesse caso o endpoint já
        // é a raiz da conta e não deve ganhar o sufixo de novo.
        return [
            'account_name' => $accountName,
            'account_key' => $accountKey,
            'endpoint' => rtrim($endpoint, '/'),
            'sas_token' => $sasToken,
        ];
    }

    /**
     * Divide `Chave=valor;Chave=valor` preservando `=` interno (a AccountKey é
     * base64 e termina em `=`), com as chaves normalizadas em minúsculas.
     *
     * @return array<string,string>
     */
    private static function segments(string $connectionString): array
    {
        $parts = [];

        foreach (explode(';', $connectionString) as $segment) {
            $position = strpos($segment, '=');

            if ($position === false || $position === 0) {
                continue;
            }

            $key = strtolower(trim(substr($segment, 0, $position)));
            $value = trim(substr($segment, $position + 1));

            if ($key !== '') {
                $parts[$key] = $value;
            }
        }

        return $parts;
    }
}
