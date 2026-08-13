<?php

namespace AzureBlob\Support;

/**
 * Traduz a seção `http` da conexão para opções do Guzzle.
 */
final class HttpOptions
{
    /**
     * @return array<string,mixed>
     */
    public static function fromConfig(Config $config): array
    {
        $options = [];

        $proxy = self::proxy($config->http['proxy'] ?? null);

        if ($proxy !== null) {
            $options['proxy'] = $proxy;
        }

        $options['connect_timeout'] = $config->connectTimeout();

        if (array_key_exists('verify', $config->http)) {
            $options['verify'] = self::boolish($config->http['verify']);
        }

        return $options;
    }

    /**
     * Aceita tanto uma string única (aplicada a http e https) quanto o array
     * com as chaves http/https/no. Devolve null quando nada foi configurado,
     * para não passar `proxy` vazio ao Guzzle.
     *
     * @return string|array<string,string|array<int,string>>|null
     */
    private static function proxy(mixed $proxy): string|array|null
    {
        if (is_string($proxy)) {
            $proxy = trim($proxy);

            return $proxy === '' ? null : $proxy;
        }

        if (! is_array($proxy)) {
            return null;
        }

        $resolved = [];

        foreach (['http', 'https'] as $scheme) {
            $value = $proxy[$scheme] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $resolved[$scheme] = trim($value);
            }
        }

        $bypass = self::bypassList($proxy['no'] ?? null);

        if ($bypass !== []) {
            $resolved['no'] = $bypass;
        }

        // Só "no" sem nenhum proxy definido não tem efeito algum.
        if (! isset($resolved['http']) && ! isset($resolved['https'])) {
            return null;
        }

        return $resolved;
    }

    /**
     * @return array<int,string>
     */
    private static function bypassList(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value)) {
            $items = explode(',', $value);
        } else {
            return [];
        }

        $items = array_map(static fn ($item) => trim((string) $item), $items);

        return array_values(array_filter($items, static fn ($item) => $item !== ''));
    }

    /**
     * `verify` pode chegar como "false" (string) vindo do .env, o que o Guzzle
     * interpretaria como caminho de um bundle de CA.
     */
    private static function boolish(mixed $value): bool|string
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['false', '0', 'off', 'no'], true)) {
                return false;
            }

            if (in_array($normalized, ['true', '1', 'on', 'yes'], true)) {
                return true;
            }

            // Caminho para um CA bundle customizado.
            return $value;
        }

        return (bool) $value;
    }
}
