# Compatibilidade com PHP 8.0

O pacote declara `"php": "^8.0"` para acompanhar o Laravel 8, que ainda roda em
PHP 8.0. Isso custa duas construções que estariam naturais em código novo.

## `readonly` (PHP 8.1+)

As propriedades promovidas de `AzureBlob\Support\Config` deveriam ser `readonly`.
Como a palavra-chave não existe em 8.0, a imutabilidade virou convenção:

- não há setters;
- `withContainer()` e `readOnly()` devolvem sempre uma instância nova;
- escrever direto em `$config->container` funciona — e não deve ser feito.

Ao abandonar o suporte a PHP 8.0 e Laravel 8, restaure com:

```php
public function __construct(
    public readonly string $connection = 'default',
    public readonly ?string $authMode = null,
    // ...
) {}
```

`withContainer()` e `readOnly()` usam `clone` seguido de atribuição, o que
`readonly` proíbe. Com PHP 8.3+ a correção é `clone with`; até lá, troque por um
construtor completo que repassa todas as propriedades.

## Enums (PHP 8.1+)

`Config::AUTH_SAS_URL`, `AUTH_CONNECTION_STRING` e `AUTH_ACCOUNT_KEY` são
constantes de string porque enums não existem em 8.0. Viram um enum natural:

```php
enum AuthMode: string
{
    case SasUrl = 'sas_url';
    case ConnectionString = 'connection_string';
    case AccountKey = 'account_key';
}
```

## O que **não** é limitação de versão

`BlobClient::__construct` usa promoção de propriedades e argumentos nomeados —
ambos são PHP 8.0 e podem ficar.

## Flysystem

Assunto separado, mas some junto: `AzureBlob\Filesystem\AzureBlobAdapterV1`
existe só porque o Laravel 8 traz o Flysystem 1. Ao largar o Laravel 8, apague
a classe e o desvio em `AzureBlobServiceProvider::makeFilesystem()`.
