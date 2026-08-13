<?php

namespace AzureBlob\Console;

/**
 * Gera uma URL assinada temporária para um blob.
 */
class SasCommand extends BlobCommand
{
    protected $signature = 'azure:sas
        {blob? : Nome do blob; omita para assinar o container inteiro}
        {--hours=1 : Horas até a expiração}
        {--permissions=r : Permissões: r=read, a=add, c=create, w=write, d=delete, l=list}'.self::COMMON_OPTIONS;

    protected $description = 'Gera uma URL com SAS token para um blob ou container';

    protected function perform(): int
    {
        $blob = $this->blob();
        $name = $this->argument('blob');
        $hours = max(1, (int) $this->option('hours'));
        $permissions = (string) $this->option('permissions');

        $url = is_string($name) && $name !== ''
            ? $blob->temporaryUrl($name, $hours, $permissions)
            : $blob->temporaryContainerUrl($hours, $permissions === 'r' ? 'rl' : $permissions);

        // Com sas_url configurada o token do container é reaproveitado: a
        // validade é a dele, não a pedida aqui.
        if ($blob->config()->usesSasToken()) {
            $this->line('<fg=yellow>Conexão em modo SAS URL: o token do container foi reaproveitado, '
                .'--hours e --permissions não se aplicam.</>');
        }

        $this->line($url);

        return self::SUCCESS;
    }
}
