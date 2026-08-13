<?php

namespace AzureBlob;

use AzureBlob\Exceptions\ConfigurationException;
use AzureBlob\Support\Config;
use AzureBlob\Support\RestClient;
use Illuminate\Contracts\Container\Container;

/**
 * Gerenciador de conexões.
 *
 * Resolve cada conexão declarada em `config/azure-blob.php` sob demanda e a
 * mantém em cache pelo tempo de vida do processo — a resolução envolve parsear
 * SAS URL/connection string, o que não vale repetir a cada chamada.
 *
 *     app(BlobManager::class)->connection('apostilas')->list();
 *     AzureBlob::connection('contratos')->upload('a.pdf', $bytes);
 *
 * Também aceita configuração ad hoc, sem passar pelo arquivo de config:
 *
 *     BlobManager::make(['sas_url' => $url]);
 *
 * @mixin BlobClient
 */
class BlobManager
{
    /** @var array<string,BlobClient> */
    private array $clients = [];

    /**
     * @param  array<string,mixed>  $config  Conteúdo de `config/azure-blob.php`.
     */
    public function __construct(
        private array $config = [],
        private ?Container $container = null,
    ) {}

    /**
     * Cliente de uma conexão nomeada. Sem argumento, usa a conexão padrão.
     *
     * @throws ConfigurationException
     */
    public function connection(?string $name = null): BlobClient
    {
        $name = $name ?: $this->defaultConnection();

        return $this->clients[$name] ??= $this->resolve($name);
    }

    /** Alias de `connection()`, no vocabulário de filesystem do Laravel. */
    public function disk(?string $name = null): BlobClient
    {
        return $this->connection($name);
    }

    /**
     * Cliente montado a partir de um array de configuração avulso.
     *
     * Útil para credenciais vindas do banco (uma conta por cliente, por
     * exemplo) sem precisar registrá-las em config/azure-blob.php.
     *
     * @param  array<string,mixed>  $connection
     *
     * @throws ConfigurationException
     */
    public function build(array $connection, string $name = 'ad-hoc'): BlobClient
    {
        return new BlobClient(new RestClient(
            Config::fromArray($connection, $name, $this->shared()),
            $this->container,
        ));
    }

    /** Descarta os clientes já resolvidos, forçando releitura da configuração. */
    public function purge(?string $name = null): void
    {
        if ($name === null) {
            $this->clients = [];

            return;
        }

        unset($this->clients[$name]);
    }

    public function defaultConnection(): string
    {
        $default = $this->config['default'] ?? 'default';

        return is_string($default) && trim($default) !== '' ? trim($default) : 'default';
    }

    /** @return array<int,string> */
    public function connectionNames(): array
    {
        $connections = $this->config['connections'] ?? [];

        return is_array($connections) ? array_keys($connections) : [];
    }

    /** @throws ConfigurationException */
    private function resolve(string $name): BlobClient
    {
        $connections = $this->config['connections'] ?? [];
        $connection = is_array($connections) ? ($connections[$name] ?? null) : null;

        if (! is_array($connection)) {
            throw ConfigurationException::unknownConnection($name);
        }

        return new BlobClient(new RestClient(
            Config::fromArray($connection, $name, $this->shared()),
            $this->container,
        ));
    }

    /**
     * Chaves globais (`http`, `logging`) que servem de base para toda conexão.
     *
     * @return array<string,mixed>
     */
    private function shared(): array
    {
        return [
            'http' => $this->config['http'] ?? [],
            'logging' => $this->config['logging'] ?? [],
        ];
    }

    /**
     * Encaminha chamadas diretas para a conexão padrão, para que
     * `AzureBlob::list()` funcione sem `->connection()`.
     *
     * @param  array<int,mixed>  $arguments
     */
    public function __call(string $method, array $arguments)
    {
        return $this->connection()->{$method}(...$arguments);
    }
}
