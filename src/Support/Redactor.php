<?php

namespace AzureBlob\Support;

/**
 * Remove credenciais do contexto antes de escrever no log.
 *
 * Um SAS token no log é uma credencial vazada: quem lê o arquivo passa a ter o
 * mesmo acesso ao container até a expiração. Vale também para a chave da conta,
 * que não expira.
 */
final class Redactor
{
    public const MASK = '[REDACTED]';

    /** Chaves cujo valor é apagado por completo. */
    private const SENSITIVE = [
        'key',
        'account_key',
        'accountkey',
        'sas_token',
        'sastoken',
        'signature',
        'sig',
        'authorization',
        'connection_string',
        'connectionstring',
        'password',
        'secret',
        'shared_access_signature',
    ];

    /**
     * @param  array<mixed>  $context
     * @return array<mixed>
     */
    public static function scrub(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            $normalized = is_string($key) ? strtolower(str_replace(['-', ' '], '_', $key)) : '';

            if ($normalized !== '' && in_array($normalized, self::SENSITIVE, true)) {
                $clean[$key] = self::MASK;

                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::scrub($value);

                continue;
            }

            $clean[$key] = is_string($value) ? self::scrubUrl($value) : $value;
        }

        return $clean;
    }

    /**
     * Substitui a assinatura de qualquer SAS embutido numa URL.
     *
     * URLs entram no contexto o tempo todo (`url`, `source`, `copy_source`) e
     * carregam o token inteiro na query string.
     */
    public static function scrubUrl(string $value): string
    {
        if (! str_contains($value, 'sig=')) {
            return $value;
        }

        return (string) preg_replace('/([?&](?:sig|signature)=)[^&\s]+/i', '$1'.self::MASK, $value);
    }
}
