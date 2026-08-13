<?php

namespace AzureBlob\Console;

/**
 * Remove um blob ou todos os blobs sob um prefixo.
 */
class DeleteCommand extends BlobCommand
{
    // Atenção: nenhuma descrição de ARGUMENTO pode conter "--". O parser de
    // assinatura do Laravel 8 casa /-{2,}(.*)/ sem âncora, então um "--algo" no
    // meio do texto faz o argumento inteiro ser lido como opção e o comando
    // quebra com 'The "blob" argument does not exist'. Corrigido só no Laravel 9+.
    protected $signature = 'azure:delete
        {blob : Nome do blob, ou o prefixo ao usar a opção recursive}
        {--recursive : Remove todos os blobs sob o prefixo}
        {--force : Não pede confirmação}'.self::COMMON_OPTIONS;

    protected $description = 'Remove um blob do Azure Blob Storage';

    protected function perform(): int
    {
        $blob = $this->blob();
        $name = (string) $this->argument('blob');

        $question = $this->option('recursive')
            ? sprintf('Remover TODOS os blobs sob "%s" no container "%s"?', $name, $blob->containerName())
            : sprintf('Remover "%s" do container "%s"?', $name, $blob->containerName());

        // A remoção é irreversível sem soft delete habilitado na conta, então a
        // confirmação é o padrão e --force é a exceção.
        if (! $this->option('force') && ! $this->confirm($question, false)) {
            $this->line('<fg=gray>Cancelado.</>');

            return self::SUCCESS;
        }

        if ($this->option('recursive')) {
            $removed = $blob->deleteDirectory($name);

            $this->info(sprintf('%d blob(s) removido(s) sob "%s".', $removed, $name));

            return self::SUCCESS;
        }

        if ($blob->delete($name)) {
            $this->info(sprintf('"%s" removido.', $name));

            return self::SUCCESS;
        }

        $this->line('<fg=gray>"'.$name.'" já não existia.</>');

        return self::SUCCESS;
    }
}
