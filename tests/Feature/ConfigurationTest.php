<?php

namespace AzureBlob\Tests\Feature;

use AzureBlob\BlobClient;
use AzureBlob\BlobManager;
use AzureBlob\Exceptions\AzureBlobException;
use AzureBlob\Exceptions\ConfigurationException;
use AzureBlob\Facades\AzureBlob;
use AzureBlob\Providers\AzureBlobServiceProvider;
use AzureBlob\Support\Config;
use AzureBlob\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;

class ConfigurationTest extends TestCase
{
    public function test_o_gerenciador_e_um_singleton_no_container(): void
    {
        $this->assertInstanceOf(BlobManager::class, $this->app->make(BlobManager::class));
        $this->assertSame($this->app->make(BlobManager::class), $this->app->make('azure-blob'));
    }

    public function test_injetar_blob_client_resolve_a_conexao_padrao(): void
    {
        $client = $this->app->make(BlobClient::class);

        $this->assertSame(self::CONTAINER, $client->containerName());
        $this->assertSame('default', $client->config()->connection);
    }

    public function test_conexoes_sao_reaproveitadas_entre_chamadas(): void
    {
        $manager = $this->app->make(BlobManager::class);

        $this->assertSame($manager->connection('sas'), $manager->connection('sas'));
        $this->assertNotSame($manager->connection('sas'), $manager->connection('default'));
    }

    public function test_purge_forca_a_reconstrucao_da_conexao(): void
    {
        $manager = $this->app->make(BlobManager::class);
        $antes = $manager->connection();

        $manager->purge('default');

        $this->assertNotSame($antes, $manager->connection());
    }

    public function test_purge_sem_argumento_limpa_todas(): void
    {
        $manager = $this->app->make(BlobManager::class);
        $antes = $manager->connection('sas');

        $manager->purge();

        $this->assertNotSame($antes, $manager->connection('sas'));
    }

    public function test_conexao_desconhecida_falha_com_mensagem_clara(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('conexão "inexistente" não encontrada');

        $this->app->make(BlobManager::class)->connection('inexistente');
    }

    public function test_disk_e_alias_de_connection(): void
    {
        $manager = $this->app->make(BlobManager::class);

        $this->assertSame($manager->connection('sas'), $manager->disk('sas'));
    }

    public function test_lista_os_nomes_e_a_conexao_padrao(): void
    {
        $manager = $this->app->make(BlobManager::class);

        $this->assertSame('default', $manager->defaultConnection());
        $this->assertSame(
            ['default', 'sas', 'connection-string', 'somente-leitura'],
            $manager->connectionNames()
        );
    }

    public function test_build_monta_uma_conexao_avulsa(): void
    {
        Http::fake(['*' => Http::response($this->listXml(), 200)]);

        $client = $this->app->make(BlobManager::class)->build([
            'sas_url' => 'https://outraconta.blob.core.windows.net/avulso?sig=xyz',
        ], 'do-banco');

        $this->assertSame('avulso', $client->containerName());
        $this->assertSame('do-banco', $client->config()->connection);

        $client->list();

        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://outraconta.blob.core.windows.net/avulso?'
        ));
    }

    public function test_facade_encaminha_para_a_conexao_padrao(): void
    {
        Http::fake(['*' => Http::response($this->listXml(), 200)]);

        AzureBlob::list();

        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            self::ENDPOINT.'/'.self::CONTAINER.'?'
        ));
    }

    public function test_container_devolve_um_cliente_novo_sem_afetar_o_original(): void
    {
        $manager = $this->app->make(BlobManager::class);
        $original = $manager->connection();
        $outro = $original->container('arquivo-morto');

        $this->assertNotSame($original, $outro);
        $this->assertSame(self::CONTAINER, $original->containerName());
        $this->assertSame('arquivo-morto', $outro->containerName());
        $this->assertSame($original, $original->container(null));
    }

    public function test_conexao_por_connection_string_opera_normalmente(): void
    {
        Http::fake(['*' => Http::response($this->listXml(), 200)]);

        $client = $this->app->make(BlobManager::class)->connection('connection-string');

        $this->assertSame(Config::AUTH_CONNECTION_STRING, $client->config()->authMode);

        $client->list();

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), self::ENDPOINT.'/via-connection-string?')
                && str_starts_with($request->header('Authorization')[0], 'SharedKey ');
        });
    }

    public function test_sas_novo_nao_pode_ser_assinado_sem_a_chave_da_conta(): void
    {
        $config = Config::fromArray([
            'sas_url' => 'https://c.blob.core.windows.net/x?sig=abc',
        ], 'so-sas');

        // No modo SAS o token existente é reaproveitado, então temporaryUrl
        // funciona; o que falha é pedir uma assinatura nova.
        $this->assertTrue($config->usesSasToken());
        $this->assertFalse($config->canSign());
    }

    public function test_o_canal_de_log_do_pacote_e_registrado(): void
    {
        $this->assertIsArray($this->app->make('config')->get('logging.channels.azure-blob'));
        $this->assertSame('daily', $this->app->make('config')->get('logging.channels.azure-blob.driver'));
    }

    public function test_canal_ja_definido_pela_aplicacao_nao_e_sobrescrito(): void
    {
        $this->app->make('config')->set('logging.channels.azure-blob', ['driver' => 'single']);

        // Registrar de novo não deve mexer no que a aplicação definiu.
        (new AzureBlobServiceProvider($this->app))->register();

        $this->assertSame('single', $this->app->make('config')->get('logging.channels.azure-blob.driver'));
    }

    public function test_o_logger_resolvido_e_um_psr_logger(): void
    {
        $client = $this->app->make(BlobManager::class)->connection();

        $this->assertInstanceOf(LoggerInterface::class, $client->client()->logger());
    }

    public function test_falha_do_azure_e_registrada_com_o_token_mascarado(): void
    {
        Http::fake(['*' => Http::response($this->errorXml('AuthorizationFailure', 'negado'), 403)]);

        $client = $this->app->make(BlobManager::class)->connection('sas');

        try {
            $client->list();
        } catch (AzureBlobException $exception) {
            // O contexto é o que vai para o log; a assinatura não pode aparecer.
            $this->assertStringNotContainsString('sig=Zm9vYmFy', json_encode($exception->context()));
        }
    }

    public function test_config_publicavel_existe_e_tem_a_estrutura_esperada(): void
    {
        $config = require __DIR__.'/../../config/azure-blob.php';

        $this->assertArrayHasKey('default', $config);
        $this->assertArrayHasKey('connections', $config);
        $this->assertArrayHasKey('default', $config['connections']);
        $this->assertArrayHasKey('http', $config);
        $this->assertArrayHasKey('logging', $config);
    }
}
