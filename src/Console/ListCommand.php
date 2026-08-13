<?php

namespace AzureBlob\Console;

use AzureBlob\BlobClient;
use AzureBlob\Results\BlobItem;
use AzureBlob\Results\Size;

/**
 * Lista blobs de um container.
 */
class ListCommand extends BlobCommand
{
    protected $signature = 'azure:list
        {prefix? : Prefixo para filtrar (ex.: pasta/subpasta/)}
        {--max=100 : Número máximo de resultados}
        {--all : Percorre todas as páginas, ignorando --max}
        {--shallow : Mostra só o nível atual, agrupando subpastas}
        {--json : Devolve o resultado em JSON}'.self::COMMON_OPTIONS;

    protected $description = 'Lista os blobs de um container do Azure Blob Storage';

    protected function perform(): int
    {
        $blob = $this->blob();
        $prefix = (string) ($this->argument('prefix') ?? '');

        [$items, $directories] = $this->fetch($blob, $prefix);

        if ($this->option('json')) {
            $this->line((string) json_encode(
                array_map(static fn (BlobItem $item): array => $item->toArray(), array_merge($directories, $items)),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            return self::SUCCESS;
        }

        if ($items === [] && $directories === []) {
            $this->line('<fg=gray>Nenhum blob encontrado'.($prefix === '' ? '' : ' com o prefixo "'.$prefix.'"').'.</>');

            return self::SUCCESS;
        }

        $this->render($items, $directories, $blob->containerName());

        return self::SUCCESS;
    }

    /**
     * Escolhe a estratégia de listagem conforme as opções.
     *
     * @return array{0:array<int,BlobItem>,1:array<int,BlobItem>} `[blobs, diretórios]`
     */
    private function fetch(BlobClient $blob, string $prefix): array
    {
        if ($this->option('all')) {
            return [iterator_to_array($blob->listAll($prefix), false), []];
        }

        if ($this->option('shallow')) {
            $page = $blob->directory($prefix, (int) $this->option('max'));

            return [$page->items, $page->directories];
        }

        return [$blob->list($prefix, (int) $this->option('max'))->items, []];
    }

    /**
     * @param  array<int,BlobItem>  $items
     * @param  array<int,BlobItem>  $directories
     */
    private function render(array $items, array $directories, string $container): void
    {
        $rows = [];

        foreach ($directories as $directory) {
            $rows[] = [$directory->name.'/', '—', '<fg=gray>pasta</>', '—'];
        }

        foreach ($items as $item) {
            $rows[] = [
                $item->name,
                $item->humanSize(),
                $item->contentType ?? '—',
                $item->lastModified?->format('d/m/Y H:i') ?? '—',
            ];
        }

        $this->table(['Blob', 'Tamanho', 'Tipo', 'Modificado'], $rows);

        $this->line(sprintf(
            '<fg=gray>%d blob(s), %s no total — container "%s".</>',
            count($items),
            Size::human(array_sum(array_map(static fn (BlobItem $item): int => $item->size, $items))),
            $container
        ));
    }
}
