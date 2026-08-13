<?php

namespace AzureBlob\Support;

/**
 * Assinatura Shared Key (HMAC-SHA256) da REST API do Azure Storage.
 *
 * Implementa o esquema "Shared Key" completo descrito em
 * https://learn.microsoft.com/rest/api/storageservices/authorize-with-shared-key
 *
 * O string-to-sign é posicional: qualquer cabeçalho fora de ordem, espaço
 * sobrando ou parâmetro de query esquecido produz um 403 AuthenticationFailed,
 * por isso cada etapa aqui é literal e sem atalhos.
 */
final class SharedKeySigner
{
    /**
     * Cabeçalhos padrão do string-to-sign, na ordem exata exigida pelo Azure.
     * `Date` fica vazio de propósito: usamos `x-ms-date`, que tem precedência.
     */
    private const SIGNED_HEADERS = [
        'Content-Encoding',
        'Content-Language',
        'Content-Length',
        'Content-MD5',
        'Content-Type',
        'Date',
        'If-Modified-Since',
        'If-Match',
        'If-None-Match',
        'If-Unmodified-Since',
        'Range',
    ];

    public function __construct(
        private string $accountName,
        private string $accountKey,
    ) {}

    /**
     * Devolve os cabeçalhos da requisição já com `Authorization` calculado.
     *
     * @param  string  $method  Verbo HTTP em maiúsculas.
     * @param  string  $url  URL absoluta, com query string se houver.
     * @param  array<string,string|int>  $headers  Cabeçalhos da requisição (sem Authorization).
     * @return array<string,string>
     */
    public function sign(string $method, string $url, array $headers = [], string $apiVersion = Config::API_VERSION): array
    {
        $headers = $this->withRequiredHeaders($headers, $apiVersion);

        $stringToSign = $this->stringToSign(strtoupper($method), $url, $headers);

        $signature = base64_encode(
            hash_hmac('sha256', $stringToSign, base64_decode($this->accountKey, true) ?: '', true)
        );

        $headers['Authorization'] = sprintf('SharedKey %s:%s', $this->accountName, $signature);

        return $headers;
    }

    /**
     * Monta o string-to-sign. Exposto para os testes conferirem o formato
     * contra os vetores da documentação sem depender da chave.
     *
     * @param  array<string,string|int>  $headers
     */
    public function stringToSign(string $method, string $url, array $headers): string
    {
        $normalized = $this->normalizeKeys($headers);

        $lines = [strtoupper($method)];

        foreach (self::SIGNED_HEADERS as $header) {
            $lines[] = $this->standardHeader($header, $normalized);
        }

        return implode("\n", $lines)."\n"
            .$this->canonicalizedHeaders($normalized)
            .$this->canonicalizedResource($url);
    }

    /**
     * `Content-Length` igual a zero entra como string vazia (comportamento
     * exigido a partir da versão 2015-02-21 da API).
     *
     * @param  array<string,string>  $headers
     */
    private function standardHeader(string $header, array $headers): string
    {
        $value = $headers[strtolower($header)] ?? '';

        if (strtolower($header) === 'content-length' && ($value === '0' || $value === '')) {
            return '';
        }

        return $value;
    }

    /**
     * Cabeçalhos `x-ms-*`: nome em minúsculas, ordenados lexicograficamente,
     * valor com espaços internos colapsados. Cada par ocupa uma linha própria.
     *
     * @param  array<string,string>  $headers
     */
    private function canonicalizedHeaders(array $headers): string
    {
        $canonical = [];

        foreach ($headers as $name => $value) {
            if (str_starts_with($name, 'x-ms-')) {
                $canonical[$name] = trim((string) preg_replace('/\s+/', ' ', (string) $value));
            }
        }

        ksort($canonical, SORT_STRING);

        $lines = '';

        foreach ($canonical as $name => $value) {
            $lines .= $name.':'.$value."\n";
        }

        return $lines;
    }

    /**
     * Recurso canônico: `/conta/caminho` seguido de uma linha por parâmetro de
     * query, com nome em minúsculas e valores repetidos ordenados e unidos por
     * vírgula.
     */
    private function canonicalizedResource(string $url): string
    {
        $parsed = parse_url($url) ?: [];
        $path = $parsed['path'] ?? '/';

        $resource = '/'.$this->accountName.($path === '' ? '/' : $path);

        if (empty($parsed['query'])) {
            return $resource;
        }

        $parameters = [];

        foreach (explode('&', $parsed['query']) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = strtolower(rawurldecode($name));

            $parameters[$name][] = rawurldecode($value);
        }

        ksort($parameters, SORT_STRING);

        foreach ($parameters as $name => $values) {
            sort($values, SORT_STRING);
            $resource .= "\n".$name.':'.implode(',', $values);
        }

        return $resource;
    }

    /**
     * `x-ms-date` e `x-ms-version` são obrigatórios e precisam estar no
     * string-to-sign, então são preenchidos aqui e não pelo chamador.
     *
     * @param  array<string,string|int>  $headers
     * @return array<string,string>
     */
    private function withRequiredHeaders(array $headers, string $apiVersion): array
    {
        $normalized = $this->normalizeKeys($headers);

        if (! isset($normalized['x-ms-date'])) {
            $headers['x-ms-date'] = gmdate('D, d M Y H:i:s \G\M\T');
        }

        if (! isset($normalized['x-ms-version'])) {
            $headers['x-ms-version'] = $apiVersion;
        }

        return array_map(static fn ($value): string => (string) $value, $headers);
    }

    /**
     * @param  array<string,string|int>  $headers
     * @return array<string,string>
     */
    private function normalizeKeys(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = (string) $value;
        }

        return $normalized;
    }
}
