<?php

namespace AzureBlob\Support;

use AzureBlob\Exceptions\ConfigurationException;

/**
 * Configuração resolvida de uma conexão.
 *
 * Tratada como imutável: não há setters e `withContainer()` devolve sempre uma
 * nova instância. As propriedades promovidas não são `readonly` porque a
 * palavra-chave não existe em PHP 8.0 — a imutabilidade é convenção, não
 * garantia do runtime. Ver docs/php-8.0-compat.md.
 */
final class Config
{
    public const AUTH_SAS_URL = 'sas_url';

    public const AUTH_CONNECTION_STRING = 'connection_string';

    public const AUTH_ACCOUNT_KEY = 'account_key';

    /** Rótulos legíveis usados em `info()` e nos logs. */
    public const AUTH_LABELS = [
        self::AUTH_SAS_URL => 'SAS URL',
        self::AUTH_CONNECTION_STRING => 'connection string',
        self::AUTH_ACCOUNT_KEY => 'conta+chave',
    ];

    /**
     * Versão da REST API usada nos cabeçalhos `x-ms-version` e na assinatura de
     * SAS. 2020-12-06+ é o que define o layout do string-to-sign em SasBuilder.
     */
    public const API_VERSION = '2022-11-02';

    /** Teto do PUT simples imposto pelo Azure para block blob (256 MiB). */
    public const MAX_SINGLE_PUT = 256 * 1024 * 1024;

    public const DEFAULTS = [
        'endpoint_suffix' => 'core.windows.net',
        'readonly' => false,
        'max_download_size' => 5242880,
        'block_size' => 4194304,
        'api_version' => self::API_VERSION,
        'http' => [
            'proxy' => [],
            'timeout' => 60,
            'connect_timeout' => 10,
            'verify' => true,
        ],
        'logging' => [
            'enabled' => true,
            'channel' => 'azure-blob',
        ],
    ];

    /**
     * @param  array<string,mixed>  $http
     * @param  array<string,mixed>  $logging
     */
    public function __construct(
        public string $connection = 'default',
        public ?string $authMode = null,
        public ?string $accountName = null,
        public ?string $accountKey = null,
        public string $sasToken = '',
        public string $accountUrl = '',
        public string $container = '',
        public bool $readonly = false,
        public int $maxDownloadSize = self::DEFAULTS['max_download_size'],
        public int $blockSize = self::DEFAULTS['block_size'],
        public string $apiVersion = self::API_VERSION,
        public array $http = self::DEFAULTS['http'],
        public array $logging = self::DEFAULTS['logging'],
    ) {}

    /**
     * Resolve uma conexão a partir do array publicado em config/azure-blob.php.
     *
     * A precedência entre modos de autenticação é SAS URL > connection string >
     * conta+chave, a mesma da ferramenta MCP que este pacote substitui.
     *
     * @param  array<string,mixed>  $connection  Seção `connections.<nome>`.
     * @param  array<string,mixed>  $shared  Chaves globais (`http`, `logging`) usadas como base.
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $connection, string $name = 'default', array $shared = []): self
    {
        $suffix = self::string($connection['endpoint_suffix'] ?? null) ?? self::DEFAULTS['endpoint_suffix'];

        $config = new self(
            connection: $name,
            container: self::string($connection['container'] ?? null) ?? '',
            readonly: self::boolean($connection['readonly'] ?? null, self::DEFAULTS['readonly']),
            maxDownloadSize: self::positiveInt($connection['max_download_size'] ?? null, self::DEFAULTS['max_download_size']),
            blockSize: self::blockSize($connection['block_size'] ?? null),
            apiVersion: self::string($connection['api_version'] ?? null) ?? self::API_VERSION,
            http: self::merge(self::DEFAULTS['http'], $shared['http'] ?? null, $connection['http'] ?? null),
            logging: self::merge(self::DEFAULTS['logging'], $shared['logging'] ?? null, $connection['logging'] ?? null),
        );

        $config->accountName = self::string($connection['name'] ?? null);
        $config->accountKey = self::string($connection['key'] ?? null);
        $config->accountUrl = rtrim(self::string($connection['url'] ?? null) ?? '', '/');

        $sasUrl = self::string($connection['sas_url'] ?? null);
        $connectionString = self::string($connection['connection_string'] ?? null);

        if ($sasUrl !== null) {
            self::applySasUrl($config, $sasUrl);
        } elseif ($connectionString !== null) {
            self::applyConnectionString($config, $connectionString, $suffix);
        } elseif ($config->accountName !== null && $config->accountKey !== null) {
            $config->authMode = self::AUTH_ACCOUNT_KEY;
            $config->accountUrl = $config->accountUrl !== ''
                ? $config->accountUrl
                : sprintf('https://%s.blob.%s', $config->accountName, $suffix);
        } else {
            throw ConfigurationException::missingCredentials($name);
        }

        if ($config->container === '') {
            throw ConfigurationException::missingContainer($name);
        }

        return $config;
    }

    /** @throws ConfigurationException */
    private static function applySasUrl(self $config, string $sasUrl): void
    {
        $parsed = SasUrl::parse($sasUrl);

        if ($parsed === null) {
            throw ConfigurationException::invalidSasUrl($config->connection);
        }

        $config->authMode = self::AUTH_SAS_URL;
        $config->sasToken = $parsed['sas_token'];
        $config->accountName = $config->accountName ?? ($parsed['account_name'] ?: null);
        $config->accountUrl = $config->accountUrl !== '' ? $config->accountUrl : $parsed['account_url'];
        // O container explícito na conexão vence o que veio embutido na URL.
        $config->container = $config->container !== '' ? $config->container : $parsed['container'];
    }

    private static function applyConnectionString(self $config, string $connectionString, string $suffix): void
    {
        $parsed = ConnectionString::parse($connectionString, $suffix);

        $config->authMode = $parsed['sas_token'] !== '' && $parsed['account_key'] === ''
            ? self::AUTH_SAS_URL
            : self::AUTH_CONNECTION_STRING;

        $config->sasToken = $parsed['sas_token'];
        $config->accountName = $config->accountName ?? ($parsed['account_name'] ?: null);
        $config->accountKey = $config->accountKey ?? ($parsed['account_key'] ?: null);
        $config->accountUrl = $config->accountUrl !== '' ? $config->accountUrl : $parsed['endpoint'];
    }

    /** Cópia apontando para outro container. */
    public function withContainer(?string $container): self
    {
        $container = self::string($container);

        if ($container === null || $container === $this->container) {
            return $this;
        }

        $clone = clone $this;
        $clone->container = $container;

        return $clone;
    }

    /** Cópia com a trava de somente leitura ligada. */
    public function readOnly(bool $readonly = true): self
    {
        if ($readonly === $this->readonly) {
            return $this;
        }

        $clone = clone $this;
        $clone->readonly = $readonly;

        return $clone;
    }

    /** True quando é possível assinar requisições/SAS com a chave da conta. */
    public function canSign(): bool
    {
        return $this->accountName !== null && $this->accountKey !== null;
    }

    public function usesSasToken(): bool
    {
        return $this->authMode === self::AUTH_SAS_URL || ($this->sasToken !== '' && ! $this->canSign());
    }

    /** URL pública do blob, sem token. */
    public function blobUrl(string $blob, ?string $container = null): string
    {
        return $this->containerUrl($container).'/'.Path::encode($blob);
    }

    public function containerUrl(?string $container = null): string
    {
        return $this->accountUrl.'/'.rawurlencode($container ?? $this->container);
    }

    public function timeout(): int
    {
        return max(1, (int) ($this->http['timeout'] ?? self::DEFAULTS['http']['timeout']));
    }

    public function connectTimeout(): int
    {
        return max(1, (int) ($this->http['connect_timeout'] ?? self::DEFAULTS['http']['connect_timeout']));
    }

    /** Descrição legível da conexão, usada por `azure:info` e pelos logs. */
    public function describe(): string
    {
        $info = 'Container: '.$this->container;

        if ($this->accountName !== null) {
            $info = 'Conta: '.$this->accountName.' | '.$info;
        }

        if (isset(self::AUTH_LABELS[$this->authMode])) {
            $info .= ' | Auth: '.self::AUTH_LABELS[$this->authMode];
        }

        if ($this->readonly) {
            $info .= ' [SOMENTE LEITURA]';
        }

        return $info;
    }

    /**
     * @param  array<string,mixed>  $defaults
     * @return array<string,mixed>
     */
    private static function merge(array $defaults, mixed $shared, mixed $override): array
    {
        return array_replace(
            $defaults,
            is_array($shared) ? $shared : [],
            is_array($override) ? $override : [],
        );
    }

    /**
     * Trata string vazia ou só com espaços como ausência de valor: `env()`
     * devolve '' com frequência e '' nunca é uma credencial válida.
     */
    private static function string(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_bool($value)) {
            return null;
        }

        $value = trim(trim((string) $value), '"');

        return $value === '' ? null : $value;
    }

    private static function boolean(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    private static function positiveInt(mixed $value, int $default): int
    {
        $value = is_numeric($value) ? (int) $value : $default;

        return $value > 0 ? $value : $default;
    }

    /**
     * O Azure exige blocos entre 1 byte e 4000 MiB e no máximo 50.000 blocos por
     * blob. O piso de 1 MiB evita estourar essa contagem em arquivos grandes.
     */
    private static function blockSize(mixed $value): int
    {
        $size = self::positiveInt($value, self::DEFAULTS['block_size']);

        return min(max($size, 1024 * 1024), self::MAX_SINGLE_PUT);
    }
}
