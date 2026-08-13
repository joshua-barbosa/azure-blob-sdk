<?php

namespace AzureBlob\Providers;

use AzureBlob\AzureBlob;
use AzureBlob\BlobClient;
use AzureBlob\Console;
use AzureBlob\Filesystem\AzureBlobAdapter;
use AzureBlob\Filesystem\AzureBlobAdapterV1;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\FilesystemAdapter as FlysystemV3Adapter;

class AzureBlobServiceProvider extends ServiceProvider
{
    /** Caminho do config publicável do pacote. */
    private const CONFIG_PATH = __DIR__.'/../../config/azure-blob.php';

    /** Nome do driver registrado em config/filesystems.php. */
    public const DRIVER = 'azure-blob';

    /** @var array<int,class-string> */
    private const COMMANDS = [
        Console\CopyCommand::class,
        Console\DeleteCommand::class,
        Console\DownloadCommand::class,
        Console\ExistsCommand::class,
        Console\InfoCommand::class,
        Console\ListCommand::class,
        Console\SasCommand::class,
        Console\UploadCommand::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'azure-blob');

        $this->registerLogChannel();

        $this->app->singleton(AzureBlob::class, function ($app): AzureBlob {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            return new AzureBlob((array) $config->get('azure-blob', []), $app);
        });

        $this->app->alias(AzureBlob::class, 'azure-blob');

        // Injetar BlobClient direto num controller resolve a conexão padrão.
        $this->app->bind(BlobClient::class, fn ($app): BlobClient => $app->make(AzureBlob::class)->connection());
    }

    public function boot(): void
    {
        $this->registerStorageDriver();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => $this->app->configPath('azure-blob.php'),
            ], 'azure-blob-config');

            $this->commands(self::COMMANDS);
        }
    }

    /**
     * Registra o driver `azure-blob` para uso em config/filesystems.php:
     *
     *     'azure' => [
     *         'driver' => 'azure-blob',
     *         'connection' => 'default',
     *         'root' => '',
     *     ],
     */
    private function registerStorageDriver(): void
    {
        if (! $this->app->bound('filesystem') || ! class_exists(Flysystem::class)) {
            return;
        }

        // O callback é reamarrado ao FilesystemManager pelo Laravel 13
        // (Closure::bind em extend()), então "$this" aqui dentro não é mais o
        // provider. Por isso a montagem do disco é uma chamada estática
        // qualificada, que independe do escopo em que a closure roda.
        Storage::extend(self::DRIVER, function ($app, array $config) {
            $manager = $app->make(AzureBlob::class);

            $client = isset($config['sas_url']) || isset($config['connection_string']) || isset($config['key'])
                ? $manager->build($config, $config['connection'] ?? 'filesystem')
                : $manager->connection($config['connection'] ?? null);

            $client = $client->container($config['container'] ?? null);

            return AzureBlobServiceProvider::makeFilesystem($client, $config);
        });
    }

    /**
     * Monta o disco do Laravel sobre o adaptador certo.
     *
     * O Laravel 8 usa Flysystem 1 e o 9+ usa o 3 — os contratos são
     * incompatíveis entre si, então a escolha é feita aqui, em tempo de
     * execução, pela presença da interface do Flysystem 3.
     *
     * @param  array<string,mixed>  $config
     */
    public static function makeFilesystem(BlobClient $client, array $config): LaravelFilesystemAdapter
    {
        $root = (string) ($config['root'] ?? '');

        // Só as chaves que o Flysystem entende: passar o array inteiro do disco
        // faria opções nossas ("connection", "container") virarem config dele.
        $flysystemConfig = array_intersect_key($config, array_flip([
            'directory_visibility', 'disable_asserts', 'temporary_url', 'url', 'visibility',
        ]));

        if (interface_exists(FlysystemV3Adapter::class)) {
            $adapter = new AzureBlobAdapter($client, $root);

            return new LaravelFilesystemAdapter(new Flysystem($adapter, $flysystemConfig), $adapter, $config);
        }

        $adapter = new AzureBlobAdapterV1($client, $root);

        // No Laravel 8 o FilesystemAdapter recebe só o Flysystem\Filesystem.
        return new LaravelFilesystemAdapter(new Flysystem($adapter, $flysystemConfig));
    }

    /**
     * Registra o canal de log do pacote em logging.channels.
     *
     * Se a aplicação já definiu um canal com esse nome, a definição dela vence:
     * o pacote nunca sobrescreve configuração explícita do usuário.
     */
    private function registerLogChannel(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make('config');

        if (! $config->get('azure-blob.logging.enabled', true)) {
            return;
        }

        $channel = $config->get('azure-blob.logging.channel');

        if (! is_string($channel) || trim($channel) === '') {
            return;
        }

        if ($config->has("logging.channels.{$channel}")) {
            return;
        }

        $definition = $config->get('azure-blob.logging.channel_config');

        if (! is_array($definition) || $definition === []) {
            return;
        }

        $config->set("logging.channels.{$channel}", $definition);
    }
}
