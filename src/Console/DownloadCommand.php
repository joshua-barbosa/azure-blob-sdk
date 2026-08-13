<?php

namespace AzureBlob\Console;

use AzureBlob\Results\Size;
use AzureBlob\Support\Path;

/**
 * Baixa um blob para o disco local ou para a saída padrão.
 */
class DownloadCommand extends BlobCommand
{
    protected $signature = 'azure:download
        {blob : Nome/caminho do blob}
        {destination? : Arquivo ou diretório local de destino (padrão: diretório atual)}
        {--stdout : Escreve o conteúdo na saída em vez de gravar em arquivo}'.self::COMMON_OPTIONS;

    protected $description = 'Baixa um blob do Azure Blob Storage';

    protected function perform(): int
    {
        $blob = $this->blob();
        $name = (string) $this->argument('blob');

        if ($this->option('stdout')) {
            $this->output->write($blob->get($name));

            return self::SUCCESS;
        }

        $destination = $this->destination($name);

        // downloadTo() usa stream: um PDF de 2 GB não passa pela memória.
        $bytes = $blob->downloadTo($name, $destination);

        $this->info(sprintf('%s → %s (%s)', $name, $destination, Size::human($bytes)));

        return self::SUCCESS;
    }

    /** Um diretório de destino recebe o arquivo com o nome original do blob. */
    private function destination(string $blob): string
    {
        $destination = $this->argument('destination');
        $destination = is_string($destination) && $destination !== '' ? $destination : getcwd();

        return is_dir($destination)
            ? rtrim($destination, '/').'/'.Path::basename($blob)
            : $destination;
    }
}
