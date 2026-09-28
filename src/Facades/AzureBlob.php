<?php

namespace AzureBlob\Facades;

use AzureBlob\BlobClient;
use AzureBlob\BlobManager;
use AzureBlob\Results\BlobContent;
use AzureBlob\Results\BlobList;
use AzureBlob\Results\BlobProperties;
use Illuminate\Support\Facades\Facade;

/**
 * Facade do gerenciador de conexões.
 *
 * Chamadas sem `connection()` são encaminhadas para a conexão padrão, então
 * `AzureBlob::list()` e `AzureBlob::connection('default')->list()` são
 * equivalentes.
 *
 * O alias global registrado é `AzureBlob`, não `Blob`: um alias `Blob` colidiria
 * com facilidade numa aplicação que já tenha a própria classe com esse nome.
 * Quem precisar do gerenciador e da facade no mesmo arquivo importa o primeiro
 * como `AzureBlob\BlobManager` — foi para isso que ele deixou de se chamar
 * `AzureBlob\AzureBlob`.
 *
 * @method static BlobClient connection(?string $name = null)
 * @method static BlobClient disk(?string $name = null)
 * @method static BlobClient build(array $connection, string $name = 'ad-hoc')
 * @method static BlobClient container(?string $container)
 * @method static BlobClient readOnly()
 * @method static void purge(?string $name = null)
 * @method static string defaultConnection()
 * @method static array connectionNames()
 * @method static string info()
 * @method static bool isReadOnly()
 * @method static string containerName()
 * @method static \AzureBlob\Support\Config config()
 * @method static \AzureBlob\Support\RestClient client()
 * @method static string path(string $blob)
 * @method static BlobList list(?string $prefix = null, int $maxResults = 100, array $options = [])
 * @method static \Generator listAll(?string $prefix = null, array $options = [])
 * @method static BlobList directory(string $path = '', int $maxResults = 5000)
 * @method static array listNames(?string $prefix = null, ?int $max = null)
 * @method static array files(string $path = '', bool $recursive = false)
 * @method static array directories(string $path = '')
 * @method static string downloadText(string $blob)
 * @method static bool containerExists()
 * @method static bool ensureContainer()
 * @method static BlobContent download(string $blob, array $options = [])
 * @method static mixed downloadJson(string $blob, bool $associative = true)
 * @method static string get(string $blob)
 * @method static resource stream(string $blob)
 * @method static int downloadTo(string $blob, string $path)
 * @method static BlobProperties properties(string $blob)
 * @method static bool exists(string $blob)
 * @method static bool missing(string $blob)
 * @method static int size(string $blob)
 * @method static \DateTimeInterface|null lastModified(string $blob)
 * @method static string|null mimeType(string $blob)
 * @method static string url(string $blob)
 * @method static string temporaryUrl(string $blob, $expiry = 1, string $permissions = 'r')
 * @method static string sasUrl(string $blob, $expiry = 1, string $permissions = 'r')
 * @method static string temporaryContainerUrl($expiry = 1, string $permissions = 'rl')
 * @method static string upload(string $blob, $contents, array $options = [])
 * @method static string uploadJson(string $blob, $data, array $options = [])
 * @method static string uploadFile(string $blob, string $path, array $options = [])
 * @method static bool setMetadata(string $blob, array $metadata)
 * @method static bool delete(string $blob)
 * @method static int deleteDirectory(string $prefix)
 * @method static string copy(string $source, string $destination, array $options = [])
 * @method static string move(string $source, string $destination, array $options = [])
 *
 * @see BlobManager
 * @see BlobClient
 */
class AzureBlob extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BlobManager::class;
    }
}
