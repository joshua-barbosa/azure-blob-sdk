<?php

namespace AzureBlob\Console;

use AzureBlob\Exceptions\AzureBlobException;

/**
 * Mostra as conexões configuradas e valida cada uma.
 *
 * É o primeiro comando a rodar depois de configurar o pacote: uma conexão que
 * aparece com erro aqui vai falhar do mesmo jeito em produção.
 */
class InfoCommand extends BlobCommand
{
    protected $signature = 'azure:info
        {--connection= : Inspeciona apenas esta conexão}
        {--check : Confirma o acesso listando um blob de cada conexão}';

    protected $description = 'Lista as conexões do Azure Blob Storage e o modo de autenticação de cada uma';

    protected function perform(): int
    {
        $only = $this->option('connection');
        $names = is_string($only) && $only !== '' ? [$only] : $this->manager->connectionNames();

        if ($names === []) {
            $this->error('Nenhuma conexão declarada em config/azure-blob.php.');

            return self::FAILURE;
        }

        $default = $this->manager->defaultConnection();
        $rows = [];
        $failed = false;

        foreach ($names as $name) {
            try {
                $client = $this->manager->connection($name);
                $status = $this->option('check') ? $this->check($name) : 'ok';
                $failed = $failed || $status !== 'ok';

                $rows[] = [
                    $name.($name === $default ? ' *' : ''),
                    $client->config()->accountName ?? '—',
                    $client->containerName(),
                    $client->config()->describe(),
                    $status,
                ];
            } catch (AzureBlobException $exception) {
                $failed = true;
                $rows[] = [$name.($name === $default ? ' *' : ''), '—', '—', '—', $exception->getMessage()];
            }
        }

        $this->table(['Conexão', 'Conta', 'Container', 'Descrição', 'Status'], $rows);
        $this->line('<fg=gray>* conexão padrão</>');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Faz uma listagem de 1 item só para provar que a credencial funciona. */
    private function check(string $name): string
    {
        try {
            $this->manager->connection($name)->list(null, 1);

            return 'ok';
        } catch (AzureBlobException $exception) {
            return $exception->getMessage();
        }
    }
}
