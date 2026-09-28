<?php

namespace AzureBlob\Tests\E2E;

use AzureBlob\BlobClient;
use AzureBlob\BlobManager;
use AzureBlob\Exceptions\BlobNotFoundException;
use AzureBlob\Tests\TestCase;

/**
 * Testes contra o Azurite, o emulador oficial do Azure Storage. É aqui que a
 * assinatura Shared Key, os SAS e os cabeçalhos de propriedade do blob são
 * validados por um servidor de verdade — o Http::fake() aceita qualquer coisa.
 *
 *     docker run -p 10000:10000 mcr.microsoft.com/azure-storage/azurite azurite-blob --blobHost 0.0.0.0
 *     AZURITE_URL=http://127.0.0.1:10000 vendor/bin/phpunit --testsuite E2E
 */
class AzuriteTest extends TestCase
{
    // Chave pública e fixa do emulador, documentada pela Microsoft.
    private const KEY = 'Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==';

    private const DEV_ACCOUNT = 'devstoreaccount1';

    private BlobClient $blob;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $url = getenv('AZURITE_URL');

        if (! is_string($url) || $url === '') {
            $this->markTestSkipped('Defina AZURITE_URL para rodar os testes contra o Azurite.');
        }

        $this->blob = (new BlobManager)->build([
            'name' => self::DEV_ACCOUNT,
            'key' => self::KEY,
            'container' => 'php-e2e-'.bin2hex(random_bytes(4)),
            'url' => rtrim($url, '/').'/'.self::DEV_ACCOUNT,
            'block_size' => 1024 * 1024,
        ]);

        $this->assertTrue($this->blob->ensureContainer());
        $this->directory = sys_get_temp_dir().'/azure-blob-e2e-'.uniqid();
    }

    protected function tearDown(): void
    {
        if (isset($this->blob)) {
            $this->blob->client()->request('DELETE', $this->blob->containerName(), ['restype' => 'container'], allow: [404]);
        }

        if (isset($this->directory) && is_dir($this->directory)) {
            array_map('unlink', glob($this->directory.'/*') ?: []);
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_container_e_propriedades_de_conteudo(): void
    {
        $this->assertTrue($this->blob->containerExists());
        $this->assertFalse($this->blob->ensureContainer());
        $this->assertFalse($this->blob->container('nao-existe-'.uniqid())->containerExists());

        // O modo do azure.py original: uma SAS URL de container.
        $sas = (new BlobManager)->build(['sas_url' => $this->blob->temporaryContainerUrl(1, 'racwdl')]);
        $this->assertTrue($sas->containerExists());

        $name = 'Relatório (final) + anexos #1 100%.txt';

        $this->blob->upload($name, 'conteúdo', [
            'cache_control' => 'no-cache',
            'content_disposition' => 'inline',
            'metadata' => ['origem' => 'e2e'],
        ]);

        $properties = $this->blob->properties($name);

        $this->assertSame('text/plain', $properties->contentType);
        $this->assertSame('no-cache', $properties->cacheControl);
        $this->assertSame('inline', $properties->contentDisposition);
        $this->assertSame(['origem' => 'e2e'], $properties->metadata);
        $this->assertSame('conteúdo', $this->blob->downloadText($name));
    }

    public function test_pastas_nomes_e_download_para_pasta_nova(): void
    {
        foreach (['2026/a.pdf', '2026/b.pdf', '2026/jan/c.pdf', 'fora.txt'] as $name) {
            $this->blob->upload($name, $name);
        }

        $this->assertSame(['2026/a.pdf', '2026/b.pdf'], $this->blob->files('2026'));
        $this->assertSame(['2026/a.pdf', '2026/b.pdf', '2026/jan/c.pdf'], $this->blob->files('2026', true));
        $this->assertSame(['2026/jan'], $this->blob->directories('2026'));
        $this->assertSame(['2026', 'fora.txt'], array_merge($this->blob->directories(), $this->blob->files()));
        $this->assertSame(['2026/a.pdf', '2026/b.pdf'], $this->blob->listNames('2026/', 2));

        $path = $this->directory.'/a.pdf';
        $this->assertSame(10, $this->blob->downloadTo('2026/a.pdf', $path));
        $this->assertSame('2026/a.pdf', file_get_contents($path));

        $this->assertSame(3, $this->blob->deleteDirectory('2026'));
    }

    public function test_upload_em_blocos_url_temporaria_e_move(): void
    {
        $payload = random_bytes((int) (2.5 * 1024 * 1024));

        $this->blob->upload('grande.bin', $payload, ['cache_control' => 'max-age=60']);

        $this->assertSame($payload, $this->blob->get('grande.bin'));
        $this->assertSame('max-age=60', $this->blob->properties('grande.bin')->cacheControl);

        $this->assertSame($payload, file_get_contents($this->blob->temporaryUrl('grande.bin')));

        $this->blob->move('grande.bin', 'movido.bin');
        $this->assertFalse($this->blob->exists('grande.bin'));

        $this->expectException(BlobNotFoundException::class);
        $this->blob->get('grande.bin');
    }
}
