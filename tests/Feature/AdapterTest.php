<?php

namespace AzureBlob\Tests\Feature;

use AzureBlob\BlobManager;
use AzureBlob\Filesystem\AzureBlobAdapter;
use AzureBlob\Support\Logger;
use AzureBlob\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Testes diretos no adaptador, sem passar pelo Storage.
 *
 * O Flysystem só chama alguns destes métodos em situações específicas, e é
 * justamente aí que uma tradução errada de exceção aparece em produção.
 */
class AdapterTest extends TestCase
{
    private function adapter(string $prefix = ''): AzureBlobAdapter
    {
        // Referenciar AzureBlobAdapter sob o Flysystem 1 é fatal: a interface
        // que ela implementa não existe lá.
        $this->requireFlysystem3();

        return new AzureBlobAdapter($this->app->make(BlobManager::class)->connection(), $prefix);
    }

    public function test_directory_exists_lista_um_item_do_prefixo(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->listXml([['name' => 'pasta/a.pdf']]), 200)
            ->push($this->listXml(), 200),
        ]);

        $this->assertTrue($this->adapter()->directoryExists('pasta'));
        $this->assertFalse($this->adapter()->directoryExists('vazia'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'maxresults=1'));
    }

    public function test_falha_de_rede_em_file_exists_vira_excecao_do_flysystem(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $adapter = $this->adapter();

        $this->expectException(UnableToCheckFileExistence::class);

        $adapter->fileExists('a.txt');
    }

    public function test_falha_de_rede_em_directory_exists_vira_excecao_do_flysystem(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $adapter = $this->adapter();

        $this->expectException(UnableToCheckFileExistence::class);

        $adapter->directoryExists('pasta');
    }

    public function test_write_stream_envia_o_conteudo(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'via stream');
        rewind($stream);

        $this->adapter()->writeStream('a.txt', $stream, new Config);
        fclose($stream);

        Http::assertSent(fn (Request $request): bool => $request->body() === 'via stream');
    }

    public function test_falha_de_escrita_vira_unable_to_write_file(): void
    {
        Http::fake(['*' => Http::response($this->errorXml('AuthorizationFailure', 'negado'), 403)]);

        $adapter = $this->adapter();

        $this->expectException(UnableToWriteFile::class);

        $adapter->write('a.txt', 'x', new Config);
    }

    public function test_falha_de_delete_diferente_de_404_vira_unable_to_delete_file(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $adapter = $this->adapter();

        $this->expectException(UnableToDeleteFile::class);

        $adapter->delete('a.txt');
    }

    public function test_metadata_expoe_tamanho_data_e_tipo(): void
    {
        Http::fake(['*' => Http::response('', 200, $this->propertyHeaders(4096, 'application/pdf'))]);

        $attributes = $this->adapter()->fileSize('a.pdf');

        $this->assertInstanceOf(FileAttributes::class, $attributes);
        $this->assertSame(4096, $attributes->fileSize());
        $this->assertSame('a.pdf', $attributes->path());
    }

    public function test_mime_type_ausente_vira_excecao_especifica(): void
    {
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '10'])]);

        $adapter = $this->adapter();

        $this->expectException(UnableToRetrieveMetadata::class);

        $adapter->mimeType('a.bin');
    }

    public function test_visibility_nao_e_suportada_para_leitura(): void
    {
        $adapter = $this->adapter();

        $this->expectException(UnableToRetrieveMetadata::class);

        $adapter->visibility('a.txt');
    }

    public function test_list_contents_raso_devolve_arquivos_e_diretorios(): void
    {
        Http::fake([
            '*' => Http::response($this->listXml([['name' => '2026/a.pdf']], ['2026/sub/']), 200),
        ]);

        $items = iterator_to_array($this->adapter()->listContents('2026', false), false);

        $this->assertInstanceOf(DirectoryAttributes::class, $items[0]);
        $this->assertSame('2026/sub', $items[0]->path());
        $this->assertInstanceOf(FileAttributes::class, $items[1]);
        $this->assertSame('2026/a.pdf', $items[1]->path());
    }

    public function test_list_contents_raso_pagina_ate_o_fim(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->listXml([['name' => 'a.pdf']], nextMarker: 'm2'), 200)
            ->push($this->listXml([['name' => 'b.pdf']]), 200),
        ]);

        $items = iterator_to_array($this->adapter()->listContents('', false), false);

        $this->assertCount(2, $items);
    }

    public function test_list_contents_profundo_nao_usa_delimitador(): void
    {
        Http::fake(['*' => Http::response($this->listXml([['name' => 'a/b/c.pdf']]), 200)]);

        $items = iterator_to_array($this->adapter()->listContents('', true), false);

        $this->assertSame('a/b/c.pdf', $items[0]->path());

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'delimiter'));
    }

    public function test_prefixo_do_disco_e_removido_da_listagem(): void
    {
        Http::fake([
            '*' => Http::response($this->listXml([['name' => 'raiz/a.pdf']], ['raiz/sub/']), 200),
        ]);

        $items = iterator_to_array($this->adapter('raiz')->listContents('', false), false);

        $this->assertSame('sub', $items[0]->path());
        $this->assertSame('a.pdf', $items[1]->path());
    }

    public function test_falha_de_copia_vira_unable_to_copy_file(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $adapter = $this->adapter();

        $this->expectException(UnableToCopyFile::class);

        $adapter->copy('a.pdf', 'b.pdf', new Config);
    }

    public function test_falha_de_move_vira_unable_to_move_file(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $adapter = $this->adapter();

        $this->expectException(UnableToMoveFile::class);

        $adapter->move('a.pdf', 'b.pdf', new Config);
    }

    public function test_create_directory_nao_faz_requisicao(): void
    {
        Http::fake();

        $this->adapter()->createDirectory('nova', new Config);

        Http::assertNothingSent();
    }

    public function test_config_do_flysystem_vira_opcoes_de_upload(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $this->adapter()->write('a.txt', 'x', new Config([
            'mimetype' => 'text/markdown',
            'CacheControl' => 'max-age=60',
            'metadata' => ['origem' => 'flysystem'],
        ]));

        Http::assertSent(function (Request $request): bool {
            return $request->header('Content-Type')[0] === 'text/markdown'
                && $request->header('Cache-Control')[0] === 'max-age=60'
                && $request->header('x-ms-meta-origem')[0] === 'flysystem';
        });
    }

    public function test_get_url_e_get_temporary_url_respeitam_o_prefixo(): void
    {
        $adapter = $this->adapter('raiz');

        $this->assertSame($this->blobUrl('raiz/a.pdf'), $adapter->getUrl('a.pdf'));

        $url = $adapter->getTemporaryUrl('a.pdf', 2, ['permissions' => 'rw']);

        $this->assertStringStartsWith($this->blobUrl('raiz/a.pdf').'?', $url);
        $this->assertStringContainsString('sp=rw', $url);
    }

    public function test_o_adaptador_expoe_o_cliente_do_sdk(): void
    {
        $this->assertSame(self::CONTAINER, $this->adapter()->client()->containerName());
    }

    // ========================================================================
    // Logger
    // ========================================================================

    public function test_logger_desligado_devolve_null_logger(): void
    {
        $config = \AzureBlob\Support\Config::fromArray([
            'name' => self::ACCOUNT,
            'key' => self::ACCOUNT_KEY,
            'container' => self::CONTAINER,
            'logging' => ['enabled' => false],
        ]);

        $this->assertInstanceOf(NullLogger::class, Logger::resolve($config, $this->app));
    }

    public function test_logger_sem_canal_definido_cai_no_padrao_da_aplicacao(): void
    {
        $config = \AzureBlob\Support\Config::fromArray([
            'name' => self::ACCOUNT,
            'key' => self::ACCOUNT_KEY,
            'container' => self::CONTAINER,
            'logging' => ['enabled' => true, 'channel' => ''],
        ]);

        $this->assertInstanceOf(LoggerInterface::class, Logger::resolve($config, $this->app));
    }

    public function test_canal_inexistente_nao_derruba_a_operacao(): void
    {
        $config = \AzureBlob\Support\Config::fromArray([
            'name' => self::ACCOUNT,
            'key' => self::ACCOUNT_KEY,
            'container' => self::CONTAINER,
            'logging' => ['enabled' => true, 'channel' => 'canal-que-nao-existe'],
        ]);

        // Melhor logar no canal padrão do que estourar por causa de log.
        $this->assertInstanceOf(LoggerInterface::class, Logger::resolve($config, $this->app));
    }

    public function test_sem_container_no_app_devolve_null_logger(): void
    {
        $config = \AzureBlob\Support\Config::fromArray([
            'name' => self::ACCOUNT,
            'key' => self::ACCOUNT_KEY,
            'container' => self::CONTAINER,
        ]);

        $vazio = new Container;

        $this->assertInstanceOf(NullLogger::class, Logger::resolve($config, $vazio));
    }
}
