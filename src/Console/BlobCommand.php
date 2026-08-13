<?php

namespace AzureBlob\Console;

use AzureBlob\AzureBlob;
use AzureBlob\BlobClient;
use AzureBlob\Exceptions\AzureBlobException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Base dos comandos `azure:*`.
 *
 * Concentra a seleção de conexão/container e a tradução de exceção do SDK em
 * código de saída, para que cada comando cuide só do próprio trabalho.
 */
abstract class BlobCommand extends Command
{
    /** Opções comuns, anexadas à assinatura de cada comando filho. */
    protected const COMMON_OPTIONS = '
        {--connection= : Conexão de config/azure-blob.php (padrão: a conexão default)}
        {--container= : Container alvo (padrão: o da conexão)}';

    public function __construct(protected AzureBlob $manager)
    {
        parent::__construct();
    }

    /**
     * Executa a ação do comando, convertendo falhas em saída legível.
     */
    public function handle(): int
    {
        try {
            return $this->perform();
        } catch (AzureBlobException $exception) {
            // $this->components só existe no Laravel 9+; error()/line() valem
            // desde o 8 e mantêm o comando funcionando na matriz inteira.
            $this->error($exception->getMessage());

            $code = $exception->errorCode();

            if ($code !== null) {
                $this->line('<fg=gray>Código do Azure: '.$code.'</>');
            }

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * A ação do comando.
     *
     * O nome evita `execute()` e `runCommand()`: ambos já existem em
     * Illuminate\Console\Command (herdados do Symfony), e redeclarar um método
     * concreto da base como abstrato é erro fatal de compilação.
     */
    abstract protected function perform(): int;

    /** Cliente já apontando para a conexão e o container pedidos. */
    protected function blob(): BlobClient
    {
        $connection = $this->option('connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;

        $container = $this->option('container');
        $container = is_string($container) && $container !== '' ? $container : null;

        return $this->manager->connection($connection)->container($container);
    }
}
