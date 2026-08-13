<?php

namespace AzureBlob\Console;

/**
 * Copia um blob, possivelmente entre containers.
 */
class CopyCommand extends BlobCommand
{
    protected $signature = 'azure:copy
        {source : Blob de origem}
        {destination : Blob de destino}
        {--from= : Container de origem (padrão: o da conexão)}
        {--to= : Container de destino (padrão: o da conexão)}
        {--move : Remove a origem depois de copiar}
        {--wait : Aguarda a conclusão de cópias assíncronas}'.self::COMMON_OPTIONS;

    protected $description = 'Copia um blob dentro do Azure Blob Storage';

    protected function perform(): int
    {
        $source = (string) $this->argument('source');
        $destination = (string) $this->argument('destination');

        $options = array_filter([
            'source_container' => $this->option('from'),
            'destination_container' => $this->option('to'),
        ], static fn ($value): bool => is_string($value) && $value !== '');

        $options['wait'] = (bool) $this->option('wait');

        $blob = $this->blob();

        $url = $this->option('move')
            ? $blob->move($source, $destination, $options)
            : $blob->copy($source, $destination, $options);

        $this->info(sprintf('%s %s → %s', $this->option('move') ? 'Movido' : 'Copiado', $source, $destination));
        $this->line('<fg=gray>'.$url.'</>');

        return self::SUCCESS;
    }
}
