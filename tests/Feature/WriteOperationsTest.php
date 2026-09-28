<?php

namespace AzureBlob\Tests\Feature;

use AzureBlob\BlobManager;
use AzureBlob\Exceptions\AzureBlobException;
use AzureBlob\Exceptions\ReadOnlyException;
use AzureBlob\Facades\AzureBlob;
use AzureBlob\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class WriteOperationsTest extends TestCase
{
    // ========================================================================
    // Upload simples
    // ========================================================================

    public function test_upload_manda_put_com_os_cabecalhos_de_block_blob(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $url = AzureBlob::upload('pasta/a.txt', 'conteúdo');

        $this->assertSame($this->blobUrl('pasta/a.txt'), $url);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PUT'
                && $request->url() === $this->blobUrl('pasta/a.txt')
                && $request->header('x-ms-blob-type')[0] === 'BlockBlob'
                && $request->header('Content-Type')[0] === 'text/plain'
                && $request->body() === 'conteúdo';
        });
    }

    public function test_content_type_e_detectado_pela_extensao(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        AzureBlob::upload('relatorio.pdf', 'x');

        Http::assertSent(fn (Request $request): bool => $request->header('Content-Type')[0] === 'application/pdf');
    }

    public function test_content_type_explicito_vence_a_deteccao(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        AzureBlob::upload('a.txt', 'x', ['content_type' => 'application/x-custom']);

        Http::assertSent(fn (Request $request): bool => $request->header('Content-Type')[0] === 'application/x-custom');
    }

    public function test_metadados_viram_cabecalhos_x_ms_meta(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        AzureBlob::upload('a.txt', 'x', ['metadata' => ['origem' => 'web', 'usuario id' => '42', '9invalido' => 'z']]);

        Http::assertSent(function (Request $request): bool {
            return $request->header('x-ms-meta-origem')[0] === 'web'
                // Espaços viram underscore: o Azure exige identificadores C#.
                && $request->header('x-ms-meta-usuario_id')[0] === '42'
                // Nome começando com dígito é descartado, não enviado quebrado.
                && ! $request->hasHeader('x-ms-meta-9invalido');
        });
    }

    public function test_cabecalhos_de_conteudo_opcionais(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        AzureBlob::upload('a.txt', 'x', [
            'cache_control' => 'max-age=3600',
            'content_disposition' => 'attachment; filename="a.txt"',
            'content_encoding' => 'gzip',
        ]);

        // Como propriedade do blob, o Azure só reconhece a forma x-ms-blob-*:
        // Cache-Control e Content-Disposition padrão são ignorados em silêncio.
        Http::assertSent(function (Request $request): bool {
            return $request->header('x-ms-blob-cache-control')[0] === 'max-age=3600'
                && $request->header('x-ms-blob-content-disposition')[0] === 'attachment; filename="a.txt"'
                && $request->header('x-ms-blob-content-encoding')[0] === 'gzip'
                && ! $request->hasHeader('Cache-Control');
        });
    }

    public function test_overwrite_false_manda_if_none_match(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        AzureBlob::upload('a.txt', 'x', ['overwrite' => false]);

        Http::assertSent(fn (Request $request): bool => $request->header('If-None-Match')[0] === '*');
    }

    public function test_conflito_ao_nao_sobrescrever_vira_excecao(): void
    {
        Http::fake(['*' => Http::response($this->errorXml('BlobAlreadyExists', 'The blob already exists.'), 409)]);

        try {
            AzureBlob::upload('a.txt', 'x', ['overwrite' => false]);
            $this->fail('Esperava AzureBlobException.');
        } catch (AzureBlobException $exception) {
            $this->assertSame(409, $exception->status());
            $this->assertSame('BlobAlreadyExists', $exception->errorCode());
        }
    }

    public function test_upload_json_serializa_e_marca_o_content_type(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        AzureBlob::uploadJson('dados.json', ['nome' => 'ação', 'itens' => [1, 2]]);

        Http::assertSent(function (Request $request): bool {
            return $request->header('Content-Type')[0] === 'application/json'
                // Acentos não viram \uXXXX: JSON_UNESCAPED_UNICODE.
                && str_contains($request->body(), '"ação"')
                && json_decode($request->body(), true) === ['nome' => 'ação', 'itens' => [1, 2]];
        });
    }

    public function test_upload_json_com_dados_nao_serializaveis_falha(): void
    {
        Http::fake();

        $this->expectException(AzureBlobException::class);
        $this->expectExceptionMessage('não foi possível serializar');

        AzureBlob::uploadJson('a.json', ["\xB1\x31"]);
    }

    public function test_upload_file_le_do_disco_e_detecta_o_tipo(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $path = sys_get_temp_dir().'/azure-blob-'.uniqid().'.json';
        file_put_contents($path, '{"a":1}');

        AzureBlob::uploadFile('destino/a.json', $path);

        Http::assertSent(function (Request $request): bool {
            return $request->body() === '{"a":1}'
                && $request->header('Content-Type')[0] === 'application/json'
                && $request->url() === $this->blobUrl('destino/a.json');
        });

        unlink($path);
    }

    public function test_upload_file_de_arquivo_inexistente_falha_antes_da_rede(): void
    {
        Http::fake();

        $this->expectException(AzureBlobException::class);
        $this->expectExceptionMessage('não existe ou não pode ser lido');

        AzureBlob::uploadFile('a.txt', '/caminho/inexistente/a.txt');

        Http::assertNothingSent();
    }

    public function test_upload_aceita_um_stream(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'via stream');
        rewind($stream);

        AzureBlob::upload('a.txt', $stream);
        fclose($stream);

        Http::assertSent(fn (Request $request): bool => $request->body() === 'via stream');
    }

    // ========================================================================
    // Upload em blocos
    // ========================================================================

    public function test_conteudo_maior_que_block_size_e_enviado_em_blocos(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $client = $this->app->make(BlobManager::class)->build([
            'name' => self::ACCOUNT,
            'key' => self::ACCOUNT_KEY,
            'container' => self::CONTAINER,
            'block_size' => 1048576,
        ], 'blocos');

        // 2,5 MiB com bloco de 1 MiB: 3 blocos + 1 commit.
        $client->upload('grande.bin', str_repeat('a', 2621440));

        Http::assertSentCount(4);

        $blocos = 0;

        Http::assertSent(function (Request $request) use (&$blocos): bool {
            if (str_contains($request->url(), 'comp=block&')) {
                $blocos++;
            }

            return true;
        });

        $this->assertSame(3, $blocos);
    }

    public function test_commit_de_blocos_manda_a_lista_e_o_tipo_do_blob(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $client = $this->app->make(BlobManager::class)->build([
            'name' => self::ACCOUNT,
            'key' => self::ACCOUNT_KEY,
            'container' => self::CONTAINER,
            'block_size' => 1048576,
        ], 'blocos');

        $client->upload('grande.pdf', str_repeat('a', 2097153));

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'comp=blocklist')) {
                return false;
            }

            return $request->header('x-ms-blob-content-type')[0] === 'application/pdf'
                && substr_count($request->body(), '<Latest>') === 3
                && str_contains($request->body(), '<Latest>'.base64_encode('block-00000000').'</Latest>');
        });
    }

    public function test_ids_de_bloco_tem_todos_o_mesmo_comprimento(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $client = $this->app->make(BlobManager::class)->build([
            'name' => self::ACCOUNT,
            'key' => self::ACCOUNT_KEY,
            'container' => self::CONTAINER,
            'block_size' => 1048576,
        ], 'blocos');

        $client->upload('grande.bin', str_repeat('a', 3145728));

        $tamanhos = [];

        Http::assertSent(function (Request $request) use (&$tamanhos): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (isset($query['blockid'])) {
                $tamanhos[] = strlen($query['blockid']);
            }

            return true;
        });

        // O Azure devolve InvalidBlockList se os ids tiverem tamanhos diferentes.
        $this->assertNotEmpty($tamanhos);
        $this->assertCount(1, array_unique($tamanhos));
    }

    // ========================================================================
    // Delete
    // ========================================================================

    public function test_delete_manda_delete_com_snapshots(): void
    {
        Http::fake(['*' => Http::response('', 202)]);

        $this->assertTrue(AzureBlob::delete('pasta/a.txt'));

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'DELETE'
                && $request->url() === $this->blobUrl('pasta/a.txt')
                && $request->header('x-ms-delete-snapshots')[0] === 'include';
        });
    }

    public function test_delete_de_blob_inexistente_devolve_false_sem_lancar(): void
    {
        Http::fake(['*' => Http::response($this->errorXml('BlobNotFound', 'não existe'), 404)]);

        $this->assertFalse(AzureBlob::delete('sumiu.txt'));
    }

    public function test_delete_directory_remove_todos_os_blobs_do_prefixo(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->listXml([['name' => '2026/a.pdf'], ['name' => '2026/b.pdf']]), 200)
            ->push('', 202)
            ->push('', 202),
        ]);

        $this->assertSame(2, AzureBlob::deleteDirectory('2026'));

        Http::assertSentCount(3);
    }

    // ========================================================================
    // Copy e move
    // ========================================================================

    public function test_copy_usa_x_ms_copy_source_com_sas_assinado(): void
    {
        Http::fake(['*' => Http::response('', 202, ['x-ms-copy-status' => 'success'])]);

        $url = AzureBlob::copy('origem/a.pdf', 'destino/a.pdf');

        $this->assertSame($this->blobUrl('destino/a.pdf'), $url);

        Http::assertSent(function (Request $request): bool {
            $source = $request->header('x-ms-copy-source')[0] ?? '';

            // O serviço do Azure lê a origem por conta própria: a URL precisa
            // carregar credencial, não bastam os cabeçalhos da nossa requisição.
            return $request->method() === 'PUT'
                && $request->url() === $this->blobUrl('destino/a.pdf')
                && str_starts_with($source, $this->blobUrl('origem/a.pdf').'?')
                && str_contains($source, 'sig=');
        });
    }

    public function test_copy_entre_containers(): void
    {
        Http::fake(['*' => Http::response('', 202, ['x-ms-copy-status' => 'success'])]);

        $url = AzureBlob::copy('a.pdf', 'b.pdf', [
            'source_container' => 'origem',
            'destination_container' => 'destino',
        ]);

        $this->assertSame(self::ENDPOINT.'/destino/b.pdf', $url);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::ENDPOINT.'/destino/b.pdf'
                && str_starts_with($request->header('x-ms-copy-source')[0], self::ENDPOINT.'/origem/a.pdf?');
        });
    }

    public function test_copy_no_modo_sas_reaproveita_o_token_do_container(): void
    {
        Http::fake(['*' => Http::response('', 202, ['x-ms-copy-status' => 'success'])]);

        $this->app->make(BlobManager::class)->connection('sas')->copy('a.pdf', 'b.pdf');

        Http::assertSent(fn (Request $request): bool => $request->header('x-ms-copy-source')[0]
            === self::ENDPOINT.'/container-sas/a.pdf?'.self::SAS_TOKEN);
    }

    public function test_move_espera_a_copia_e_apaga_a_origem(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('', 202, ['x-ms-copy-status' => 'success'])
            ->push('', 202),
        ]);

        AzureBlob::move('origem.pdf', 'destino.pdf');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === $this->blobUrl('origem.pdf'));
    }

    public function test_copia_pendente_e_aguardada_quando_wait_esta_ligado(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('', 202, ['x-ms-copy-status' => 'pending'])
            ->push('', 200, ['x-ms-copy-status' => 'pending'])
            ->push('', 200, ['x-ms-copy-status' => 'success']),
        ]);

        AzureBlob::copy('a.pdf', 'b.pdf', ['wait' => true, 'timeout' => 5]);

        Http::assertSentCount(3);
    }

    // ========================================================================
    // Metadados
    // ========================================================================

    public function test_set_metadata_usa_comp_metadata(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $this->assertTrue(AzureBlob::setMetadata('a.pdf', ['origem' => 'lote-2026']));

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PUT'
                && str_contains($request->url(), 'comp=metadata')
                && $request->header('x-ms-meta-origem')[0] === 'lote-2026';
        });
    }

    // ========================================================================
    // Somente leitura
    // ========================================================================

    public function test_conexao_somente_leitura_bloqueia_escritas_antes_da_rede(): void
    {
        Http::fake();

        $client = $this->app->make(BlobManager::class)->connection('somente-leitura');

        foreach ([
            'upload' => fn () => $client->upload('a.txt', 'x'),
            'delete' => fn () => $client->delete('a.txt'),
            'copy' => fn () => $client->copy('a.txt', 'b.txt'),
            'setMetadata' => fn () => $client->setMetadata('a.txt', []),
            'deleteDirectory' => fn () => $client->deleteDirectory('pasta'),
        ] as $operacao => $chamada) {
            try {
                $chamada();
                $this->fail(sprintf('"%s" deveria ter sido bloqueada.', $operacao));
            } catch (ReadOnlyException $exception) {
                $this->assertStringContainsString('somente leitura', $exception->getMessage());
                $this->assertSame($operacao, $exception->context()['operation']);
            }
        }

        Http::assertNothingSent();
    }

    public function test_read_only_trava_uma_conexao_normal_sem_afetar_a_original(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $client = $this->app->make(BlobManager::class)->connection();
        $travado = $client->readOnly();

        $this->assertFalse($client->isReadOnly());
        $this->assertTrue($travado->isReadOnly());

        // A original continua escrevendo.
        $client->upload('a.txt', 'x');

        $this->expectException(ReadOnlyException::class);
        $travado->upload('a.txt', 'x');
    }

    public function test_leitura_continua_permitida_em_conexao_somente_leitura(): void
    {
        Http::fake(['*' => Http::response($this->listXml(), 200)]);

        $list = $this->app->make(BlobManager::class)->connection('somente-leitura')->list();

        $this->assertTrue($list->isEmpty());
    }
}
