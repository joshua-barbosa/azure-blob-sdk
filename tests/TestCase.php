<?php

namespace AzureBlob\Tests;

use AzureBlob\Facades\AzureBlob;
use AzureBlob\Providers\AzureBlobServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Artisan;
use League\Flysystem\FilesystemAdapter;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** Chave da conta usada nos testes; precisa ser base64 válido para assinar. */
    public const ACCOUNT_KEY = 'Y2hhdmUtZGUtdGVzdGUtc2VjcmV0YS0xMjM0NTY3ODkw';

    public const ACCOUNT = 'contateste';

    public const CONTAINER = 'meu-container';

    public const ENDPOINT = 'https://contateste.blob.core.windows.net';

    /** SAS token fixo, com a mesma cara de um emitido pelo Azure. */
    public const SAS_TOKEN = 'sp=racwdl&st=2026-01-01T00:00:00Z&se=2027-01-01T00:00:00Z&sv=2022-11-02&sr=c&sig=Zm9vYmFyc2lnbmF0dXJlMTIz%3D';

    protected function getPackageProviders($app): array
    {
        return [AzureBlobServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Blob' => AzureBlob::class];
    }

    protected function defineEnvironment($app): void
    {
        tap($app->make('config'), function (Repository $config): void {
            $config->set('azure-blob.default', 'default');

            $config->set('azure-blob.connections', [
                // Modo conta+chave: exercita a assinatura Shared Key.
                'default' => [
                    'name' => self::ACCOUNT,
                    'key' => self::ACCOUNT_KEY,
                    'container' => self::CONTAINER,
                ],

                // Modo SAS URL: credencial vai na query, sem assinatura.
                'sas' => [
                    'sas_url' => self::ENDPOINT.'/container-sas?'.self::SAS_TOKEN,
                ],

                'connection-string' => [
                    'connection_string' => 'DefaultEndpointsProtocol=https;AccountName='.self::ACCOUNT
                        .';AccountKey='.self::ACCOUNT_KEY.';EndpointSuffix=core.windows.net',
                    'container' => 'via-connection-string',
                ],

                'somente-leitura' => [
                    'name' => self::ACCOUNT,
                    'key' => self::ACCOUNT_KEY,
                    'container' => self::CONTAINER,
                    'readonly' => true,
                ],
            ]);

            $config->set('filesystems.disks.azure', [
                'driver' => 'azure-blob',
                'connection' => 'default',
            ]);

            $config->set('filesystems.disks.azure-prefixado', [
                'driver' => 'azure-blob',
                'connection' => 'default',
                'root' => 'raiz',
            ]);
        });
    }

    /**
     * Pula o teste quando o Flysystem 3 não está instalado.
     *
     * O Laravel 8 traz o Flysystem 1, cujo contrato de adaptador é outro: as
     * classes e exceções de `League\Flysystem\*` referenciadas nestes testes
     * simplesmente não existem lá. O caminho do Laravel 8 é coberto pelos
     * testes que passam por `Storage::disk()`.
     */
    protected function requireFlysystem3(): void
    {
        if (! interface_exists(FilesystemAdapter::class)) {
            $this->markTestSkipped('Requer Flysystem 3 (Laravel 9+).');
        }
    }

    /** True quando o Flysystem em uso é o 1.x, do Laravel 8. */
    protected function usingFlysystem1(): bool
    {
        return ! interface_exists(FilesystemAdapter::class);
    }

    /** Código de saída do último `runCommand()`. */
    protected int $lastExitCode = 0;

    /**
     * Roda um comando e devolve a saída completa.
     *
     * `expectsOutputToContain()` casa uma expectativa por escrita no console:
     * dois trechos na MESMA linha nunca são satisfeitos juntos. Para conferir
     * várias coisas de uma linha só (uma linha de tabela, uma URL com vários
     * parâmetros), capturar a saída inteira é o caminho confiável.
     *
     * Vale também para a compatibilidade com o Laravel 8, onde
     * `expectsOutputToContain()` ainda não existe.
     *
     * @param  array<string,mixed>  $parameters
     */
    protected function runCommand(string $command, array $parameters = []): string
    {
        $this->lastExitCode = Artisan::call($command, $parameters);

        return Artisan::output();
    }

    /** URL de um blob no container padrão dos testes. */
    protected function blobUrl(string $blob, string $container = self::CONTAINER): string
    {
        return self::ENDPOINT.'/'.$container.'/'.$blob;
    }

    /**
     * XML de resposta de `List Blobs`.
     *
     * @param  array<int,array<string,mixed>>  $blobs
     * @param  array<int,string>  $prefixes
     */
    protected function listXml(array $blobs = [], array $prefixes = [], ?string $nextMarker = null, string $prefix = ''): string
    {
        $items = '';

        foreach ($blobs as $blob) {
            $items .= sprintf(
                '<Blob><Name>%s</Name><Properties>'
                .'<Creation-Time>%s</Creation-Time><Last-Modified>%s</Last-Modified>'
                .'<Etag>%s</Etag><Content-Length>%d</Content-Length><Content-Type>%s</Content-Type>'
                .'<BlobType>BlockBlob</BlobType></Properties></Blob>',
                htmlspecialchars((string) $blob['name'], ENT_XML1),
                $blob['created'] ?? 'Mon, 05 Jan 2026 10:00:00 GMT',
                $blob['modified'] ?? 'Tue, 06 Jan 2026 12:30:00 GMT',
                $blob['etag'] ?? '0x8DAABBCC',
                $blob['size'] ?? 1024,
                $blob['type'] ?? 'application/pdf',
            );
        }

        foreach ($prefixes as $blobPrefix) {
            $items .= '<BlobPrefix><Name>'.htmlspecialchars($blobPrefix, ENT_XML1).'</Name></BlobPrefix>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<EnumerationResults ServiceEndpoint="'.self::ENDPOINT.'/" ContainerName="'.self::CONTAINER.'">'
            .'<Prefix>'.htmlspecialchars($prefix, ENT_XML1).'</Prefix>'
            .'<Blobs>'.$items.'</Blobs>'
            .'<NextMarker>'.($nextMarker ?? '').'</NextMarker>'
            .'</EnumerationResults>';
    }

    /**
     * Cabeçalhos de uma resposta de `Get Blob Properties`.
     *
     * @return array<string,string>
     */
    protected function propertyHeaders(int $size = 1024, string $contentType = 'application/pdf'): array
    {
        return [
            'Content-Length' => (string) $size,
            'Content-Type' => $contentType,
            'Last-Modified' => 'Tue, 06 Jan 2026 12:30:00 GMT',
            'ETag' => '"0x8DAABBCC"',
            'x-ms-blob-type' => 'BlockBlob',
            'x-ms-creation-time' => 'Mon, 05 Jan 2026 10:00:00 GMT',
            'x-ms-meta-origem' => 'teste',
        ];
    }

    /** Corpo de erro no formato que o Azure devolve. */
    protected function errorXml(string $code, string $message): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><Error><Code>'.$code.'</Code>'
            .'<Message>'.$message."\nRequestId:abc\nTime:2026-01-06T12:00:00Z</Message></Error>";
    }
}
