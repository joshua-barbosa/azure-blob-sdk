<?php

namespace AzureBlob\Tests\Feature;

use AzureBlob\BlobManager;
use AzureBlob\Exceptions\AzureBlobException;
use AzureBlob\Exceptions\BlobNotFoundException;
use AzureBlob\Facades\AzureBlob;
use AzureBlob\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Identificadores de rastreio da requisição e o protocolo dos SAS gerados.
 */
class RequestTracingTest extends TestCase
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function test_toda_requisicao_leva_um_x_ms_client_request_id_proprio(): void
    {
        Http::fake(['*' => Http::response('', 200, $this->propertyHeaders())]);

        AzureBlob::exists('a.pdf');
        AzureBlob::connection('sas')->exists('a.pdf');

        $ids = [];

        Http::assertSent(function (Request $request) use (&$ids): bool {
            $ids[] = $request->header('x-ms-client-request-id')[0] ?? '';

            return true;
        });

        $this->assertCount(2, $ids);
        $this->assertMatchesRegularExpression(self::UUID, $ids[0]);
        $this->assertMatchesRegularExpression(self::UUID, $ids[1]);
        $this->assertNotSame($ids[0], $ids[1]);
    }

    public function test_erro_carrega_os_ids_de_rastreio_no_contexto(): void
    {
        Http::fake(['*' => Http::response(
            $this->errorXml('ServerBusy', 'ocupado'),
            503,
            ['x-ms-request-id' => 'f1e2d3c4-0000-1111-2222-333344445555'],
        )]);

        try {
            AzureBlob::list();
            $this->fail('Esperava AzureBlobException.');
        } catch (AzureBlobException $exception) {
            $this->assertSame('f1e2d3c4-0000-1111-2222-333344445555', $exception->requestId());
            $this->assertSame('f1e2d3c4-0000-1111-2222-333344445555', $exception->context()['request_id']);
            $this->assertMatchesRegularExpression(self::UUID, (string) $exception->clientRequestId());

            Http::assertSent(fn (Request $request): bool => $request->header('x-ms-client-request-id')[0] === $exception->clientRequestId());
        }
    }

    public function test_404_tambem_carrega_os_ids_de_rastreio(): void
    {
        Http::fake(['*' => Http::response('', 404, ['x-ms-request-id' => 'req-404'])]);

        try {
            AzureBlob::get('sumiu.pdf');
            $this->fail('Esperava BlobNotFoundException.');
        } catch (BlobNotFoundException $exception) {
            $this->assertSame('req-404', $exception->requestId());
            $this->assertMatchesRegularExpression(self::UUID, (string) $exception->clientRequestId());
        }
    }

    public function test_sem_x_ms_request_id_na_resposta_o_contexto_fica_nulo(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        try {
            AzureBlob::list();
            $this->fail('Esperava AzureBlobException.');
        } catch (AzureBlobException $exception) {
            $this->assertNull($exception->requestId());
        }
    }

    public function test_sas_gerado_em_endpoint_https_so_vale_por_https(): void
    {
        parse_str((string) parse_url(AzureBlob::temporaryUrl('pasta/a.pdf'), PHP_URL_QUERY), $blob);
        parse_str((string) parse_url(AzureBlob::temporaryContainerUrl(), PHP_URL_QUERY), $container);

        $this->assertSame('https', $blob['spr']);
        $this->assertSame('https', $container['spr']);
    }

    public function test_sas_gerado_em_endpoint_http_nao_restringe_o_protocolo(): void
    {
        // Emulador (Azurite): um SAS com spr=https seria recusado em http.
        $azurite = (new BlobManager)->build([
            'name' => 'devstoreaccount1',
            'key' => self::ACCOUNT_KEY,
            'container' => 'c',
            'url' => 'http://127.0.0.1:10000/devstoreaccount1',
        ]);

        parse_str((string) parse_url($azurite->temporaryUrl('a.pdf'), PHP_URL_QUERY), $query);

        $this->assertArrayNotHasKey('spr', $query);
    }
}
