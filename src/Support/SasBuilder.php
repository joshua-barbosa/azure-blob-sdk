<?php

namespace AzureBlob\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Gera Service SAS de blob ou de container assinados com a chave da conta.
 *
 * O layout do string-to-sign vale para `sv` >= 2020-12-06; versões anteriores
 * têm menos campos e produziriam assinatura inválida.
 *
 * @see https://learn.microsoft.com/rest/api/storageservices/create-service-sas
 */
final class SasBuilder
{
    /**
     * Ordem canônica das permissões. O Azure valida a string `sp` posicional:
     * "rw" é aceito, "wr" devolve AuthenticationFailed.
     */
    private const PERMISSION_ORDER = ['r', 'a', 'c', 'w', 'd', 'x', 'y', 'l', 't', 'f', 'm', 'e', 'o', 'p', 'i'];

    public function __construct(
        private string $accountName,
        private string $accountKey,
        private string $apiVersion = Config::API_VERSION,
    ) {}

    /**
     * Token SAS (sem `?`) para um blob específico.
     *
     * @param  DateTimeInterface|int  $expiry  Instante de expiração, ou horas a partir de agora.
     */
    public function forBlob(
        string $container,
        string $blob,
        $expiry = 1,
        string $permissions = 'r',
        ?DateTimeInterface $start = null,
        ?string $ip = null,
    ): string {
        return $this->build(
            resource: 'b',
            canonicalizedResource: sprintf(
                '/blob/%s/%s/%s',
                $this->accountName,
                trim($container, '/'),
                Path::normalize($blob)
            ),
            expiry: $expiry,
            permissions: $permissions,
            start: $start,
            ip: $ip,
        );
    }

    /**
     * Token SAS (sem `?`) para o container inteiro.
     *
     * @param  DateTimeInterface|int  $expiry
     */
    public function forContainer(
        string $container,
        $expiry = 1,
        string $permissions = 'rl',
        ?DateTimeInterface $start = null,
        ?string $ip = null,
    ): string {
        return $this->build(
            resource: 'c',
            canonicalizedResource: sprintf('/blob/%s/%s', $this->accountName, trim($container, '/')),
            expiry: $expiry,
            permissions: $permissions,
            start: $start,
            ip: $ip,
        );
    }

    /**
     * @param  DateTimeInterface|int  $expiry
     */
    private function build(
        string $resource,
        string $canonicalizedResource,
        $expiry,
        string $permissions,
        ?DateTimeInterface $start,
        ?string $ip,
    ): string {
        $signedPermissions = self::normalizePermissions($permissions);
        $signedExpiry = self::instant($expiry);
        $signedStart = $start === null ? '' : self::format($start);
        $signedIp = $ip === null ? '' : trim($ip);

        // Ordem posicional obrigatória para sv >= 2020-12-06. Os campos vazios
        // continuam ocupando a própria linha.
        $stringToSign = implode("\n", [
            $signedPermissions,
            $signedStart,
            $signedExpiry,
            $canonicalizedResource,
            '',              // signedIdentifier (stored access policy)
            $signedIp,
            '',              // signedProtocol — vazio significa https,http
            $this->apiVersion,
            $resource,
            '',              // signedSnapshotTime
            '',              // signedEncryptionScope
            '',              // rscc  Cache-Control
            '',              // rscd  Content-Disposition
            '',              // rsce  Content-Encoding
            '',              // rscl  Content-Language
            '',              // rsct  Content-Type
        ]);

        $signature = base64_encode(
            hash_hmac('sha256', $stringToSign, base64_decode($this->accountKey, true) ?: '', true)
        );

        $query = [
            'sv' => $this->apiVersion,
            'sr' => $resource,
            'sp' => $signedPermissions,
            'se' => $signedExpiry,
            'sig' => $signature,
        ];

        if ($signedStart !== '') {
            $query['st'] = $signedStart;
        }

        if ($signedIp !== '') {
            $query['sip'] = $signedIp;
        }

        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Deduplica e reordena as permissões conforme PERMISSION_ORDER,
     * descartando letras que o Azure não reconhece.
     */
    public static function normalizePermissions(string $permissions): string
    {
        $requested = str_split(strtolower(trim($permissions)));

        $ordered = array_filter(
            self::PERMISSION_ORDER,
            static fn (string $letter): bool => in_array($letter, $requested, true)
        );

        return implode('', $ordered) ?: 'r';
    }

    /**
     * Aceita um instante explícito ou um número de horas a partir de agora — a
     * forma abreviada que os comandos Artisan e `temporaryUrl()` usam.
     *
     * @param  DateTimeInterface|int  $expiry
     */
    private static function instant($expiry): string
    {
        if ($expiry instanceof DateTimeInterface) {
            return self::format($expiry);
        }

        $hours = max(1, (int) $expiry);

        return self::format(
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->add(new DateInterval('PT'.$hours.'H'))
        );
    }

    /** ISO 8601 em UTC, o único formato aceito em `st`/`se`. */
    private static function format(DateTimeInterface $moment): string
    {
        return DateTimeImmutable::createFromFormat('U', (string) $moment->getTimestamp())
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
