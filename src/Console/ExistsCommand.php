<?php

namespace AzureBlob\Console;

/**
 * Verifica a existência de um blob e mostra suas propriedades.
 *
 * Sai com código 1 quando o blob não existe, o que torna o comando usável
 * direto em scripts de shell.
 */
class ExistsCommand extends BlobCommand
{
    protected $signature = 'azure:exists
        {blob : Nome/caminho do blob}
        {--json : Devolve as propriedades em JSON}'.self::COMMON_OPTIONS;

    protected $description = 'Verifica se um blob existe e mostra suas propriedades';

    protected function perform(): int
    {
        $blob = $this->blob();
        $name = (string) $this->argument('blob');

        if (! $blob->exists($name)) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['exists' => false, 'name' => $name]));
            } else {
                $this->error(sprintf('"%s" não existe no container "%s".', $name, $blob->containerName()));
            }

            return self::FAILURE;
        }

        $properties = $blob->properties($name);

        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['exists' => true] + $properties->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($properties->toArray() as $key => $value) {
            if ($value === null || $value === [] || $key === 'metadata') {
                continue;
            }

            $rows[] = [$key, $key === 'size' ? $properties->humanSize().' ('.$value.' bytes)' : (string) $value];
        }

        foreach ($properties->metadata as $key => $value) {
            $rows[] = ['meta.'.$key, $value];
        }

        $this->table(['Propriedade', 'Valor'], $rows);

        return self::SUCCESS;
    }
}
