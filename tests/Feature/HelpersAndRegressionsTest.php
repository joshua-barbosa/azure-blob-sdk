<?php

namespace AzureBlob\Tests\Feature;

use AzureBlob\Exceptions\AzureBlobException;
use AzureBlob\Exceptions\BlobNotFoundException;
use AzureBlob\Exceptions\ReadOnlyException;
use AzureBlob\Facades\AzureBlob;
use AzureBlob\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Atalhos que vieram do `azure.py` do vetorizador (listar nomes, pastas,
 * texto, garantir container) e regressões encontradas no porte para Node.
 */
class HelpersAndRegressionsTest extends TestCase
{
    // ========================================================================
    // Pastas e nomes
    // ========================================================================

    public function test_list_names_percorre_todas_as_paginas_ate_o_teto(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->listXml([['name' => 'a.pdf'], ['name' => 'b.pdf']], nextMarker: 'm2'), 200)
            ->push($this->listXml([['name' => 'c.pdf']]), 200),
        ]);

        $this->assertSame(['a.pdf', 'b.pdf', 'c.pdf'], AzureBlob::listNames());
    }

    public function test_list_names_para_no_teto_sem_buscar_mais_paginas(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->listXml([['name' => 'a.pdf'], ['name' => 'b.pdf']], nextMarker: 'm2'), 200),
        ]);

        $this->assertSame(['a.pdf'], AzureBlob::listNames('apostilas/', 1));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'prefix=apostilas%2F'));
    }

    public function test_files_e_directories_leem_uma_pasta_com_paginacao(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->listXml([['name' => '2026/a.pdf']], ['2026/janeiro/'], nextMarker: 'm2'), 200)
            ->push($this->listXml([['name' => '2026/b.pdf']], ['2026/fevereiro/']), 200)
            ->push($this->listXml([['name' => '2026/a.pdf']], ['2026/janeiro/'], nextMarker: 'm2'), 200)
            ->push($this->listXml([['name' => '2026/b.pdf']], ['2026/fevereiro/']), 200),
        ]);

        $this->assertSame(['2026/a.pdf', '2026/b.pdf'], AzureBlob::files('/2026'));
        $this->assertSame(['2026/janeiro', '2026/fevereiro'], AzureBlob::directories('2026/'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'delimiter=%2F')
            && str_contains($request->url(), 'marker=m2'));
    }

    public function test_files_recursivo_lista_sem_delimitador(): void
    {
        Http::fake(['*' => Http::response($this->listXml([['name' => '2026/a.pdf'], ['name' => '2026/jan/b.pdf']]), 200)]);

        $this->assertSame(['2026/a.pdf', '2026/jan/b.pdf'], AzureBlob::files('2026', true));

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'delimiter='));
    }

    public function test_download_text_devolve_o_conteudo(): void
    {
        Http::fake(['*' => Http::response('olá, mundo', 200)]);

        $this->assertSame('olá, mundo', AzureBlob::downloadText('a.txt'));
    }

    // ========================================================================
    // Container
    // ========================================================================

    public function test_container_exists_usa_head_com_restype_e_trata_404(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 200)->push('', 404)]);

        $this->assertTrue(AzureBlob::containerExists());
        $this->assertFalse(AzureBlob::containerExists());

        Http::assertSent(fn (Request $request): bool => $request->method() === 'HEAD'
            && $request->url() === self::ENDPOINT.'/'.self::CONTAINER.'?restype=container');
    }

    public function test_container_exists_no_modo_sas_usa_listagem(): void
    {
        Http::fake(['*' => Http::response($this->listXml(), 200)]);

        $this->assertTrue(AzureBlob::connection('sas')->containerExists());

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'comp=list')
            && str_contains($request->url(), 'maxresults=1'));
    }

    public function test_ensure_container_cria_ou_reconhece_o_existente(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('', 201)
            ->push($this->errorXml('ContainerAlreadyExists', 'existe'), 409, ['x-ms-error-code' => 'ContainerAlreadyExists']),
        ]);

        $this->assertTrue(AzureBlob::ensureContainer());
        $this->assertFalse(AzureBlob::ensureContainer());

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/'.self::CONTAINER.'?restype=container'));
    }

    public function test_ensure_container_nao_confunde_container_sendo_apagado_com_existente(): void
    {
        Http::fake(['*' => Http::response(
            $this->errorXml('ContainerBeingDeleted', 'apagando'),
            409,
            ['x-ms-error-code' => 'ContainerBeingDeleted'],
        )]);

        $this->expectException(AzureBlobException::class);
        $this->expectExceptionMessage('ContainerBeingDeleted');

        AzureBlob::ensureContainer();
    }

    public function test_ensure_container_respeita_somente_leitura(): void
    {
        Http::fake();

        $this->expectException(ReadOnlyException::class);

        try {
            AzureBlob::readOnly()->ensureContainer();
        } finally {
            Http::assertNothingSent();
        }
    }

    // ========================================================================
    // Regressões
    // ========================================================================

    public function test_copia_que_termina_failed_lanca_e_move_nao_apaga_a_origem(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('', 202, ['x-ms-copy-status' => 'pending'])
            ->push('', 200, ['x-ms-copy-status' => 'failed', 'x-ms-copy-status-description' => '403 AuthorizationFailure']),
        ]);

        try {
            AzureBlob::move('origem.pdf', 'destino.pdf', ['destination_container' => 'backup']);
            $this->fail('A cópia falha deveria lançar.');
        } catch (AzureBlobException $exception) {
            $this->assertSame(
                'azure-blob: a cópia para "destino.pdf" terminou com status "failed" (403 AuthorizationFailure).',
                $exception->getMessage(),
            );
        }

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_copia_que_ja_volta_failed_lanca(): void
    {
        Http::fake(['*' => Http::response('', 202, ['x-ms-copy-status' => 'aborted'])]);

        $this->expectExceptionMessage('status "aborted"');

        AzureBlob::copy('a.pdf', 'b.pdf');
    }

    public function test_move_para_o_mesmo_blob_e_recusado_sem_requisicao(): void
    {
        Http::fake();

        try {
            AzureBlob::move('/a.pdf', 'a.pdf');
            $this->fail('Mover sobre si mesmo deveria lançar.');
        } catch (AzureBlobException $exception) {
            $this->assertStringContainsString('são o mesmo blob', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_download_to_cria_a_pasta_e_nao_destroi_o_arquivo_em_falha(): void
    {
        $directory = sys_get_temp_dir().'/azure-blob-'.uniqid();
        $path = $directory.'/sub/arquivo.txt';

        Http::fake(['*' => Http::sequence()
            ->push('versão nova', 200)
            ->push($this->errorXml('BlobNotFound', 'não existe'), 404),
        ]);

        $this->assertSame(12, AzureBlob::downloadTo('a.txt', $path));
        $this->assertSame('versão nova', file_get_contents($path));

        try {
            AzureBlob::downloadTo('sumiu.txt', $path);
            $this->fail('O 404 deveria lançar.');
        } catch (BlobNotFoundException) {
            // esperado
        }

        $this->assertSame('versão nova', file_get_contents($path));
        $this->assertSame(['arquivo.txt'], array_values(array_diff(scandir($directory.'/sub'), ['.', '..'])));

        unlink($path);
        rmdir($directory.'/sub');
        rmdir($directory);
    }

    public function test_delete_directory_exige_prefixo(): void
    {
        Http::fake();

        foreach (['', '/'] as $prefix) {
            try {
                AzureBlob::deleteDirectory($prefix);
                $this->fail('Prefixo vazio deveria lançar.');
            } catch (AzureBlobException $exception) {
                $this->assertStringContainsString('exige um prefixo', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_delete_directory_usa_o_nome_exato_da_listagem(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->listXml([['name' => 'dir//duplo.txt'], ['name' => 'dir/espaco ']]), 200)
            ->push('', 202)
            ->push('', 202),
        ]);

        $this->assertSame(2, AzureBlob::deleteDirectory('dir'));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === $this->blobUrl('dir//duplo.txt'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === $this->blobUrl('dir/espaco%20'));
    }

    public function test_upload_file_prefere_o_tipo_do_nome_do_blob(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $temp = tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($temp, '%PDF');

        try {
            AzureBlob::uploadFile('apostilas/a.pdf', $temp);
        } finally {
            unlink($temp);
        }

        Http::assertSent(fn (Request $request): bool => $request->header('Content-Type')[0] === 'application/pdf');
    }
}
