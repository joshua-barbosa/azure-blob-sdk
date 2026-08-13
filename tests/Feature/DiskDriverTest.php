<?php

namespace AzureBlob\Tests\Feature;

use AzureBlob\Filesystem\AzureBlobAdapter;
use AzureBlob\Filesystem\AzureBlobAdapterV1;
use AzureBlob\Tests\TestCase;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToSetVisibility;

/**
 * O driver `azure-blob` registrado em config/filesystems.php.
 *
 * A ponte com o Flysystem é onde erros passam despercebidos: uma exceção do SDK
 * que não vire a exceção esperada pelo Flysystem faz Storage::get() estourar em
 * vez de devolver false/null.
 */
class DiskDriverTest extends TestCase
{
    public function test_o_disco_e_montado_com_o_adaptador_do_pacote(): void
    {
        $disk = Storage::disk('azure');

        // O provider escolhe o adaptador pela versão do Flysystem instalada.
        if ($this->usingFlysystem1()) {
            $this->assertInstanceOf(AzureBlobAdapterV1::class, $disk->getDriver()->getAdapter());

            return;
        }

        $this->assertInstanceOf(AzureBlobAdapter::class, $disk->getAdapter());
    }

    public function test_put_envia_o_blob(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $this->assertTrue(Storage::disk('azure')->put('pasta/a.txt', 'conteúdo'));

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PUT'
                && $request->url() === $this->blobUrl('pasta/a.txt')
                && $request->body() === 'conteúdo';
        });
    }

    public function test_get_devolve_o_conteudo(): void
    {
        Http::fake(['*' => Http::response('conteúdo', 200)]);

        $this->assertSame('conteúdo', Storage::disk('azure')->get('a.txt'));
    }

    public function test_get_de_blob_inexistente_devolve_null(): void
    {
        Http::fake(['*' => Http::response($this->errorXml('BlobNotFound', 'não existe'), 404)]);

        // Diferença do framework, não do pacote: o Laravel 8 lança
        // FileNotFoundException; do 9 em diante get() devolve null.
        if ($this->usingFlysystem1()) {
            $this->expectException(FileNotFoundException::class);

            Storage::disk('azure')->get('sumiu.txt');

            return;
        }

        // O Laravel converte UnableToReadFile em null; se a exceção do SDK
        // vazasse crua, este get() estouraria.
        $this->assertNull(Storage::disk('azure')->get('sumiu.txt'));
    }

    public function test_exists_e_missing(): void
    {
        // O Flysystem resolve has() como fileExists() || directoryExists(),
        // então o caminho ausente gasta duas requisições: o HEAD e a listagem.
        Http::fake(['*' => Http::sequence()
            ->push('', 200, $this->propertyHeaders())
            ->push('', 404)
            ->push($this->listXml(), 200),
        ]);

        $this->assertTrue(Storage::disk('azure')->exists('a.txt'));
        $this->assertTrue(Storage::disk('azure')->missing('b.txt'));
    }

    public function test_delete_remove_o_blob(): void
    {
        Http::fake(['*' => Http::response('', 202)]);

        $this->assertTrue(Storage::disk('azure')->delete('a.txt'));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_delete_de_inexistente_e_considerado_sucesso(): void
    {
        Http::fake(['*' => Http::response($this->errorXml('BlobNotFound', 'não existe'), 404)]);

        // No Laravel 8 o Flysystem 1 exige que o arquivo exista e o framework
        // converte a falha em `false`; do 9 em diante o delete é idempotente.
        $this->assertSame(
            ! $this->usingFlysystem1(),
            Storage::disk('azure')->delete('sumiu.txt')
        );
    }

    public function test_size_last_modified_e_mime_type(): void
    {
        Http::fake(['*' => Http::response('', 200, $this->propertyHeaders(4096, 'application/pdf'))]);

        $disk = Storage::disk('azure');

        $this->assertSame(4096, $disk->size('a.pdf'));
        $this->assertSame('application/pdf', $disk->mimeType('a.pdf'));
        $this->assertSame(
            '2026-01-06 12:30:00',
            date('Y-m-d H:i:s', $disk->lastModified('a.pdf'))
        );
    }

    public function test_files_lista_o_nivel_atual(): void
    {
        Http::fake([
            '*' => Http::response($this->listXml(
                [['name' => '2026/a.pdf'], ['name' => '2026/b.pdf']],
                ['2026/sub/']
            ), 200),
        ]);

        $files = Storage::disk('azure')->files('2026');

        $this->assertSame(['2026/a.pdf', '2026/b.pdf'], $files);
    }

    public function test_directories_lista_as_subpastas(): void
    {
        Http::fake([
            '*' => Http::response($this->listXml([['name' => '2026/a.pdf']], ['2026/sub/']), 200),
        ]);

        $this->assertSame(['2026/sub'], Storage::disk('azure')->directories('2026'));
    }

    public function test_all_files_percorre_recursivamente(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->listXml([['name' => 'a.pdf']], nextMarker: 'm2'), 200)
            ->push($this->listXml([['name' => 'sub/b.pdf']]), 200),
        ]);

        $this->assertSame(['a.pdf', 'sub/b.pdf'], Storage::disk('azure')->allFiles());
    }

    public function test_copy_e_move(): void
    {
        // O Flysystem 1 confere que a origem existe e o destino não antes de
        // copiar, então só o HEAD do destino pode responder 404 — o PUT da
        // cópia vai para a mesma URL e precisa continuar dando 202.
        Http::fake(function (Request $request) {
            return $request->method() === 'HEAD' && str_ends_with($request->url(), '/b.pdf')
                ? Http::response('', 404)
                : Http::response('', 202, ['x-ms-copy-status' => 'success']);
        });

        $this->assertTrue(Storage::disk('azure')->copy('a.pdf', 'b.pdf'));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-ms-copy-source'));
    }

    public function test_url_e_temporary_url_saem_do_adaptador(): void
    {
        $disk = Storage::disk('azure');

        $this->assertSame($this->blobUrl('a.pdf'), $disk->url('a.pdf'));

        $url = $disk->temporaryUrl('a.pdf', now()->addHours(2));

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('r', $query['sp']);
        $this->assertNotEmpty($query['sig']);
    }

    public function test_root_do_disco_prefixa_os_caminhos(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        Storage::disk('azure-prefixado')->put('a.txt', 'x');

        Http::assertSent(fn (Request $request): bool => $request->url() === $this->blobUrl('raiz/a.txt'));
    }

    public function test_listagem_remove_o_prefixo_do_disco(): void
    {
        Http::fake([
            '*' => Http::response($this->listXml([['name' => 'raiz/a.pdf']], ['raiz/sub/']), 200),
        ]);

        $disk = Storage::disk('azure-prefixado');

        $this->assertSame(['a.pdf'], $disk->files());
    }

    public function test_delete_directory_remove_tudo_sob_o_prefixo(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->listXml([['name' => '2026/a.pdf'], ['name' => '2026/b.pdf']]), 200)
            ->push('', 202)
            ->push('', 202),
        ]);

        $this->assertTrue(Storage::disk('azure')->deleteDirectory('2026'));
    }

    public function test_make_directory_e_um_no_op_bem_sucedido(): void
    {
        Http::fake();

        // O Azure não tem diretórios: eles aparecem quando um blob é criado.
        $this->assertTrue(Storage::disk('azure')->makeDirectory('nova-pasta'));

        Http::assertNothingSent();
    }

    public function test_visibilidade_por_blob_nao_e_suportada(): void
    {
        $this->requireFlysystem3();

        $this->expectException(UnableToSetVisibility::class);

        Storage::disk('azure')->getAdapter()->setVisibility('a.txt', 'public');
    }

    public function test_read_stream_devolve_um_recurso(): void
    {
        Http::fake(['*' => Http::response('conteúdo em stream', 200)]);

        $stream = Storage::disk('azure')->readStream('a.txt');

        $this->assertIsResource($stream);
        $this->assertSame('conteúdo em stream', stream_get_contents($stream));

        fclose($stream);
    }

    public function test_adaptador_converte_falha_de_leitura_na_excecao_do_flysystem(): void
    {
        $this->requireFlysystem3();

        Http::fake(['*' => Http::response('', 500)]);

        $this->expectException(UnableToReadFile::class);

        Storage::disk('azure')->getAdapter()->read('a.txt');
    }

    public function test_put_file_as_com_content_type(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        Storage::disk('azure')->put('a.txt', 'x', ['mimetype' => 'text/markdown']);

        // O Flysystem 1 faz um HEAD antes do PUT, e esse HEAD não tem corpo nem
        // Content-Type — daí a asserção olhar só o PUT.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && ($request->header('Content-Type')[0] ?? null) === 'text/markdown');
    }
}
