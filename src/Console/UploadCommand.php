<?php

namespace AzureBlob\Console;

/**
 * Envia um arquivo local para um blob.
 */
class UploadCommand extends BlobCommand
{
    protected $signature = 'azure:upload
        {file : Arquivo local a enviar}
        {blob? : Nome/caminho do blob (padrão: o nome do arquivo)}
        {--content-type= : Content-Type; detectado pela extensão quando omitido}
        {--no-overwrite : Falha em vez de sobrescrever um blob existente}'.self::COMMON_OPTIONS;

    protected $description = 'Envia um arquivo para o Azure Blob Storage';

    protected function perform(): int
    {
        $file = (string) $this->argument('file');
        $name = (string) ($this->argument('blob') ?? basename($file));

        $options = ['overwrite' => ! $this->option('no-overwrite')];

        $contentType = $this->option('content-type');

        if (is_string($contentType) && $contentType !== '') {
            $options['content_type'] = $contentType;
        }

        $url = $this->blob()->uploadFile($name, $file, $options);

        $this->info(sprintf('%s → %s', $file, $name));
        $this->line('<fg=gray>'.$url.'</>');

        return self::SUCCESS;
    }
}
