<?php

namespace AzureBlob\Tests\Feature;

use AzureBlob\AzureBlob;
use AzureBlob\Exceptions\AzureBlobException;
use AzureBlob\Exceptions\BlobNotFoundException;
use AzureBlob\Exceptions\BlobTooLargeException;
use AzureBlob\Facades\Blob;
use AzureBlob\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class ReadOperationsTest extends TestCase
{
    // ========================================================================
    // Listagem
    // ========================================================================

    public function test_lista_blobs_com_os_parametros_corretos(): void
    {
        Http::fake([
            '*' => Http::response($this->listXml([
                ['name' => '2026/a.pdf', 'size' => 2048],
                ['name' => '2026/b.pdf', 'size' => 1024],
            ], prefix: '2026/'), 200),
        ]);

        $list = Blob::list('2026/', 50);

        $this->assertCount(2, $list);
        $this->assertSame(['2026/a.pdf', '2026/b.pdf'], $list->names());
        $this->assertSame(3072, $list->totalSize());

        Http::assertSent(function (Request $request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), self::ENDPOINT.'/'.self::CONTAINER.'?')
                && $query['restype'] === 'container'
                && $query['comp'] === 'list'
                && $query['prefix'] === '2026/'
                && $query['maxresults'] === '50';
        });
    }

    public function test_assina_a_requisicao_com_shared_key(): void
    {
        Http::fake(['*' => Http::response($this->listXml(), 200)]);

        Blob::list();

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->header('Authorization')[0] ?? '', 'SharedKey '.self::ACCOUNT.':')
                && $request->hasHeader('x-ms-date')
                && $request->header('x-ms-version')[0] === '2022-11-02';
        });
    }

    public function test_conexao_sas_manda_o_token_na_query_e_nao_assina(): void
    {
        Http::fake(['*' => Http::response($this->listXml(), 200)]);

        $this->app->make(AzureBlob::class)->connection('sas')->list();

        Http::assertSent(function (Request $request): bool {
            return ! $request->hasHeader('Authorization')
                && str_contains($request->url(), 'sig=Zm9vYmFyc2lnbmF0dXJlMTIz%3D')
                && str_contains($request->url(), '/container-sas?');
        });
    }

    public function test_max_results_e_limitado_ao_teto_do_azure(): void
    {
        Http::fake(['*' => Http::response($this->listXml(), 200)]);

        Blob::list(null, 999999);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'maxresults=5000'));
    }

    public function test_list_all_segue_o_next_marker_ate_o_fim(): void
    {
        $paginas = [
            $this->listXml([['name' => 'a.pdf']], nextMarker: 'marca-2'),
            $this->listXml([['name' => 'b.pdf']], nextMarker: 'marca-3'),
            $this->listXml([['name' => 'c.pdf']]),
        ];

        Http::fake(['*' => Http::sequence()
            ->push($paginas[0], 200)
            ->push($paginas[1], 200)
            ->push($paginas[2], 200),
        ]);

        $nomes = [];

        foreach (Blob::listAll() as $item) {
            $nomes[] = $item->name;
        }

        $this->assertSame(['a.pdf', 'b.pdf', 'c.pdf'], $nomes);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'marker=marca-2')
            || str_contains($request->url(), 'marker=marca-3')
            || ! str_contains($request->url(), 'marker='));
    }

    public function test_directory_usa_delimitador_e_devolve_subpastas(): void
    {
        Http::fake([
            '*' => Http::response($this->listXml([['name' => '2026/a.pdf']], ['2026/janeiro/']), 200),
        ]);

        $list = Blob::directory('2026');

        $this->assertCount(1, $list->items);
        $this->assertCount(1, $list->directories);
        $this->assertSame('2026/janeiro', $list->directories[0]->name);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'delimiter=%2F')
            && str_contains($request->url(), 'prefix=2026%2F'));
    }

    // ========================================================================
    // Download
    // ========================================================================

    public function test_download_devolve_conteudo_e_propriedades(): void
    {
        Http::fake([
            '*' => Http::response('conteúdo do arquivo', 200, $this->propertyHeaders(21, 'text/plain')),
        ]);

        $content = Blob::download('pasta/a.txt');

        $this->assertSame('conteúdo do arquivo', $content->contents());
        $this->assertSame('pasta/a.txt', $content->name);
        $this->assertSame('text/plain', $content->contentType);
        $this->assertSame(21, $content->size);
        $this->assertSame('teste', $content->properties->metadata['origem']);

        // Um HEAD para as propriedades e um GET para o corpo.
        Http::assertSentCount(2);
    }

    public function test_download_recusa_blob_acima_do_limite(): void
    {
        Http::fake(['*' => Http::response('', 200, $this->propertyHeaders(10 * 1024 * 1024))]);

        $this->expectException(BlobTooLargeException::class);
        $this->expectExceptionMessage('excede o limite');

        Blob::download('grande.zip');
    }

    public function test_limite_de_download_pode_ser_elevado_na_chamada(): void
    {
        Http::fake(['*' => Http::response('ok', 200, $this->propertyHeaders(10 * 1024 * 1024))]);

        $content = Blob::download('grande.zip', ['max_size' => 50 * 1024 * 1024]);

        $this->assertSame('ok', $content->contents());
    }

    public function test_get_baixa_sem_checar_tamanho(): void
    {
        Http::fake(['*' => Http::response('bytes', 200)]);

        $this->assertSame('bytes', Blob::get('a.bin'));
        Http::assertSentCount(1);
    }

    public function test_download_json_decodifica_o_corpo(): void
    {
        Http::fake([
            '*' => Http::response('{"total":2,"itens":["a","b"]}', 200, ['Content-Type' => 'application/json']),
        ]);

        $this->assertSame(['total' => 2, 'itens' => ['a', 'b']], Blob::downloadJson('dados.json'));
    }

    public function test_download_json_com_corpo_invalido_lanca_excecao(): void
    {
        Http::fake(['*' => Http::response('nao e json', 200, ['Content-Type' => 'application/json'])]);

        $this->expectException(AzureBlobException::class);
        $this->expectExceptionMessage('não contém JSON válido');

        Blob::downloadJson('dados.json');
    }

    public function test_download_to_grava_no_disco(): void
    {
        Http::fake(['*' => Http::response('conteudo em stream', 200)]);

        $path = sys_get_temp_dir().'/azure-blob-'.uniqid().'.txt';

        $bytes = Blob::downloadTo('a.txt', $path);

        $this->assertSame(18, $bytes);
        $this->assertSame('conteudo em stream', file_get_contents($path));

        unlink($path);
    }

    // ========================================================================
    // Propriedades e existência
    // ========================================================================

    public function test_properties_usa_head_e_hidrata_os_metadados(): void
    {
        Http::fake(['*' => Http::response('', 200, $this->propertyHeaders(4096))]);

        $properties = Blob::properties('pasta/a.pdf');

        $this->assertSame(4096, $properties->size);
        $this->assertSame('application/pdf', $properties->contentType);
        $this->assertSame('meu-container', $properties->container);
        $this->assertSame(['origem' => 'teste'], $properties->metadata);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'HEAD'
            && $request->url() === $this->blobUrl('pasta/a.pdf'));
    }

    public function test_exists_devolve_true_e_false_sem_lancar_para_404(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push('', 200, $this->propertyHeaders())
                ->push('', 404),
        ]);

        $this->assertTrue(Blob::exists('existe.pdf'));
        $this->assertFalse(Blob::exists('nao-existe.pdf'));
    }

    public function test_missing_e_o_inverso_de_exists(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->assertTrue(Blob::missing('x.pdf'));
    }

    public function test_atalhos_de_metadados(): void
    {
        Http::fake(['*' => Http::response('', 200, $this->propertyHeaders(2048, 'image/png'))]);

        $this->assertSame(2048, Blob::size('a.png'));
        $this->assertSame('image/png', Blob::mimeType('a.png'));
        $this->assertSame('2026-01-06T12:30:00+00:00', Blob::lastModified('a.png')->format('c'));
    }

    public function test_404_em_properties_vira_blob_not_found(): void
    {
        Http::fake(['*' => Http::response($this->errorXml('BlobNotFound', 'The specified blob does not exist.'), 404)]);

        try {
            Blob::properties('sumiu.pdf');
            $this->fail('Esperava BlobNotFoundException.');
        } catch (BlobNotFoundException $exception) {
            $this->assertSame(404, $exception->status());
            $this->assertSame('BlobNotFound', $exception->errorCode());
            $this->assertStringContainsString('sumiu.pdf', $exception->getMessage());
            $this->assertSame('meu-container', $exception->context()['container']);
        }
    }

    public function test_erro_do_azure_e_traduzido_com_codigo_e_mensagem(): void
    {
        Http::fake([
            '*' => Http::response($this->errorXml('AuthenticationFailed', 'Server failed to authenticate.'), 403),
        ]);

        try {
            Blob::list();
            $this->fail('Esperava AzureBlobException.');
        } catch (AzureBlobException $exception) {
            $this->assertSame(403, $exception->status());
            $this->assertSame('AuthenticationFailed', $exception->errorCode());
            $this->assertSame('Server failed to authenticate.', $exception->context()['error_message']);
        }
    }

    public function test_erro_sem_corpo_usa_o_cabecalho_x_ms_error_code(): void
    {
        Http::fake(['*' => Http::response('', 409, ['x-ms-error-code' => 'BlobAlreadyExists'])]);

        try {
            Blob::list();
            $this->fail('Esperava AzureBlobException.');
        } catch (AzureBlobException $exception) {
            $this->assertSame('BlobAlreadyExists', $exception->errorCode());
        }
    }

    // ========================================================================
    // URLs
    // ========================================================================

    public function test_url_publica_codifica_o_nome(): void
    {
        $this->assertSame(
            self::ENDPOINT.'/'.self::CONTAINER.'/pasta%20a/rela%C3%A7%C3%A3o.pdf',
            Blob::url('pasta a/relação.pdf')
        );
    }

    public function test_temporary_url_assina_um_sas_novo_no_modo_chave(): void
    {
        $url = Blob::temporaryUrl('pasta/a.pdf', 2, 'rw');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith($this->blobUrl('pasta/a.pdf').'?', $url);
        $this->assertSame('rw', $query['sp']);
        $this->assertSame('b', $query['sr']);
        $this->assertNotEmpty($query['sig']);
    }

    public function test_temporary_url_reaproveita_o_token_no_modo_sas(): void
    {
        $client = $this->app->make(AzureBlob::class)->connection('sas');

        $url = $client->temporaryUrl('a.pdf', 5, 'rwd');

        // Com sas_url o token do container manda: expiração e permissões
        // pedidas aqui não têm como ser aplicadas.
        $this->assertSame(self::ENDPOINT.'/container-sas/a.pdf?'.self::SAS_TOKEN, $url);
    }

    public function test_temporary_container_url(): void
    {
        $url = Blob::temporaryContainerUrl(1, 'rl');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith(self::ENDPOINT.'/'.self::CONTAINER.'?', $url);
        $this->assertSame('c', $query['sr']);
        $this->assertSame('rl', $query['sp']);
    }

    public function test_sas_url_e_alias_de_temporary_url(): void
    {
        Http::fake();

        $this->assertSame(
            parse_url(Blob::sasUrl('a.pdf'), PHP_URL_PATH),
            parse_url(Blob::temporaryUrl('a.pdf'), PHP_URL_PATH),
        );
    }
}
