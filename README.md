# Azure Blob SDK

[![Packagist](https://img.shields.io/packagist/v/joshua-barbosa/azure-blob-sdk.svg)](https://packagist.org/packages/joshua-barbosa/azure-blob-sdk)
[![Tests](https://github.com/joshua-barbosa/azure-blob-sdk/actions/workflows/tests.yml/badge.svg)](https://github.com/joshua-barbosa/azure-blob-sdk/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/joshua-barbosa/azure-blob-sdk/php.svg)](https://packagist.org/packages/joshua-barbosa/azure-blob-sdk)
[![License](https://img.shields.io/packagist/l/joshua-barbosa/azure-blob-sdk.svg)](LICENSE)
[![Buy Me A Coffee](https://img.shields.io/badge/buy%20me%20a%20coffee-%E2%98%95-FFDD00)](https://www.buymeacoffee.com/joshuabarbosa)

SDK PHP para o [Azure Blob Storage](https://learn.microsoft.com/rest/api/storageservices/blob-service-rest-api), integrado ao Laravel.

Suporta **Laravel 8 a 13** / **PHP 8.0+**.

A REST API do Azure é implementada direto sobre o cliente HTTP do Laravel
(`Illuminate\Http`), com assinatura **Shared Key** (HMAC-SHA256) e geração de
**Service SAS** nativas. **Não depende do `microsoft/azure-storage-blob`**, que
está abandonado desde 2021 e não suporta PHP 8.2+ — é ele que costuma travar a
atualização de projetos que falam com o Azure.

```php
use AzureBlob\Facades\Blob;

Blob::list('apostilas/2026/');
Blob::download('apostilas/2026/matematica.pdf')->saveTo('/tmp/mat.pdf');
Blob::upload('apostilas/2026/nova.pdf', $conteudo);
Blob::temporaryUrl('apostilas/2026/matematica.pdf', 2);

Blob::connection('contratos')->uploadJson('metadados.json', $dados);
```

## Compatibilidade

| Laravel | PHP | Flysystem | Testado no CI | Situação |
|---|---|---|---|---|
| 13.x | 8.3 · 8.4 | 3.x | ✅ | Recomendado |
| 12.x | 8.3 | 3.x | ✅ | Recomendado |
| 11.x | 8.2 | 3.x | ✅ | ⚠️ Suporte a ser removido |
| 10.x | 8.1 | 3.x | ✅ | ⚠️ Suporte a ser removido |
| 9.x | 8.1 | 3.x | ✅ | ⚠️ Suporte a ser removido |
| 8.x | 8.0 | 1.x | ✅ | ⚠️ Suporte a ser removido |

> ### ⚠️ Laravel 8, 9, 10 e 11 serão descontinuados
>
> O suporte a essas versões existe como **medida paliativa**, para que projetos
> legados consigam usar o SDK enquanto a migração não acontece. Ele **será removido
> numa versão futura**, sem prazo definido mas sem cerimônia — provavelmente na
> primeira versão que precisar de um recurso mais novo do framework.
>
> Todas as quatro já estão **fora do suporte oficial da Laravel** e carregam
> advisories de segurança conhecidas. Na prática isso significa que o Composer 2.10+
> **recusa instalá-las por padrão** — se o seu projeto está numa delas, você já
> convive com esse bloqueio, independentemente deste pacote.
>
> O Laravel 8 custa mais caro aqui do que nos outros pacotes: ele traz o **Flysystem 1**,
> cujo contrato de adaptador é incompatível com o do 3, então o driver de Storage
> existe em duas implementações. Ver [docs/filesystem.md](docs/filesystem.md).
>
> **Migre para o Laravel 12 ou 13.** Enquanto isso não é viável, fixe a versão do
> SDK para não ser surpreendido quando o suporte cair:
>
> ```bash
> composer require joshua-barbosa/azure-blob-sdk:~0.1.0
> ```
>
> O `~0.1.0` aceita correções (`0.1.1`, `0.1.2`) mas não sobe para `0.2.0`, que é
> onde a remoção pode acontecer.

O piso de PHP 8.0 custou alguns recursos de 8.1+ (`readonly`, `enum`).
O que foi cedido e como reverter está em [docs/php-8.0-compat.md](docs/php-8.0-compat.md).

## Instalação

```bash
composer require joshua-barbosa/azure-blob-sdk
```

O ServiceProvider e a facade `Blob` são descobertos automaticamente — nada a registrar manualmente.

> O pacote está em `0.x`: a API pública ainda pode mudar. O SemVer permite alterações
> incompatíveis entre versões `0.x` diferentes, então fixe em `~0.1.0` se quiser
> proteção contra quebras — sobretudo se você depende do suporte a Laravel 8–11.

### Desenvolvimento local

Para editar o pacote e ver o efeito imediato na aplicação, use um repositório `path`:

```json
{
    "repositories": [
        { "type": "path", "url": "../azure-blob-sdk", "options": { "symlink": true } }
    ],
    "require": { "joshua-barbosa/azure-blob-sdk": "@dev" }
}
```

Com `symlink: true` o Composer aponta o `vendor/` para a sua cópia local: você edita o pacote e o
efeito é imediato, sem `composer update`.

### Publicar a configuração

```bash
php artisan vendor:publish --tag=azure-blob-config
```

Isso cria `config/azure-blob.php` na aplicação. O pacote funciona sem publicar — os padrões vêm do
próprio arquivo interno —, mas publicar é o caminho para declarar múltiplas contas e versionar
ajustes de timeout, proxy e log.

## Configuração

Há três modos de autenticação, resolvidos nesta ordem de precedência.

### Modo 1 — SAS URL (recomendado)

```dotenv
AZURE_STORAGE_SAS_URL="https://minhaconta.blob.core.windows.net/meu-container?sp=racwdl&st=...&se=...&sv=2022-11-02&sr=c&sig=..."
```

A URL já carrega o endpoint da conta, o container e o token — `AZURE_STORAGE_CONTAINER`
é opcional e, quando definido, sobrescreve o container embutido na URL. É o modo mais
seguro: a chave da conta nunca entra na aplicação.

### Modo 2 — Connection string

```dotenv
AZURE_STORAGE_CONNECTION_STRING="DefaultEndpointsProtocol=https;AccountName=minhaconta;AccountKey=...;EndpointSuffix=core.windows.net"
AZURE_STORAGE_CONTAINER=meu-container
```

### Modo 3 — Conta + chave

```dotenv
AZURE_STORAGE_NAME=minhaconta
AZURE_STORAGE_KEY=base64DaChaveDaConta==
AZURE_STORAGE_CONTAINER=meu-container
```

Só os modos 2 e 3 conseguem **assinar SAS novos**. No modo 1 o pacote reaproveita o
token do container, então a validade e as permissões de `temporaryUrl()` são as do
token configurado. Detalhes em [docs/authentication.md](docs/authentication.md).

### Múltiplas contas

Declare quantas conexões precisar em `config/azure-blob.php` e selecione pelo nome:

```php
'connections' => [

    'default' => [
        'sas_url'   => env('AZURE_STORAGE_SAS_URL'),
        'container' => env('AZURE_STORAGE_CONTAINER'),
    ],

    'apostilas' => [
        'sas_url'  => env('APOSTILAS_AZURE_STORAGE_SAS_URL'),
        'readonly' => true,
    ],

    'contratos' => [
        'name'      => env('CONTRATOS_AZURE_STORAGE_NAME'),
        'key'       => env('CONTRATOS_AZURE_STORAGE_KEY'),
        'container' => 'contratos-assinados',
    ],

],
```

```php
Blob::connection('apostilas')->list();
```

Credenciais que não vêm do arquivo de config (uma conta por cliente, guardada no
banco) podem ser montadas na hora:

```php
$blob = app(AzureBlob::class)->build(['sas_url' => $cliente->azure_sas_url]);
```

### Proxy

Ambientes corporativos costumam exigir saída por proxy. Basta definir:

```dotenv
AZURE_PROXY=http://usuario:senha@proxy.empresa.com.br:8080
AZURE_PROXY_NO=localhost,.interno.empresa.com.br
```

Use `AZURE_PROXY_HTTP` e `AZURE_PROXY_HTTPS` quando precisar separá-los.
`verify` aceita `false` ou o caminho de um CA bundle customizado — um `"false"`
vindo do `.env` como string é convertido para booleano, senão o Guzzle o
interpretaria como caminho de arquivo.

### Timeouts e limites

```dotenv
AZURE_TIMEOUT=60                  # resposta completa (s); por bloco em uploads fatiados
AZURE_CONNECT_TIMEOUT=10          # conexão TCP (s)
AZURE_MAX_DOWNLOAD_SIZE=5242880   # teto do download() em memória
AZURE_BLOCK_SIZE=4194304          # acima disso o upload vai em blocos
AZURE_API_VERSION=2022-11-02      # versão da REST API
AZURE_READONLY=false              # trava local de escrita
```

Não há retry automático: `Put Blob` não é idempotente quando combinado com
metadados e condicionais, e uma retentativa cega pode sobrescrever o que outro
processo acabou de gravar.

### Log

```dotenv
AZURE_LOG_ENABLED=true
AZURE_LOG_CHANNEL=azure-blob      # null manda para o canal padrão da aplicação
AZURE_LOG_LEVEL=debug
AZURE_LOG_DAYS=14
```

O canal `azure-blob` é registrado automaticamente em `logging.channels`, a menos
que a aplicação já tenha um canal com esse nome — nesse caso o dela prevalece.

Chaves de conta, SAS tokens, connection strings e a assinatura (`sig=`) dentro de
URLs são **mascarados** antes de qualquer escrita. Um SAS em arquivo de log é uma
credencial vazada: quem lê o arquivo passa a ter o mesmo acesso ao container até
a expiração.

## Uso

### Facade

```php
use AzureBlob\Facades\Blob;

Blob::list('2026/');                    // conexão padrão
Blob::connection('apostilas')->list();  // conexão nomeada
```

### Injeção de dependência

```php
use AzureBlob\AzureBlob;
use AzureBlob\BlobClient;

public function __construct(private BlobClient $blob) {}      // conexão padrão
public function __construct(private AzureBlob $azure) {}      // gerenciador

$this->azure->connection('contratos')->upload('a.pdf', $bytes);
```

### Listar

```php
$lista = Blob::list('2026/', maxResults: 100);

foreach ($lista as $item) {
    echo $item->name, ' ', $item->humanSize(), PHP_EOL;
}

// Todas as páginas, sem carregar tudo na memória
foreach (Blob::listAll('2026/') as $item) { /* ... */ }

// Listagem rasa: subpastas agrupadas em vez da árvore inteira
$nivel = Blob::directory('2026');
$nivel->directories;   // BlobItem[] com isDirectory = true
```

### Baixar

```php
$conteudo = Blob::download('a.pdf');    // BlobContent
$conteudo->contents();
$conteudo->saveTo('/tmp/a.pdf');

Blob::downloadJson('dados.json');               // array direto
Blob::downloadTo('grande.zip', '/tmp/g.zip');   // stream para disco
```

`download()` recusa blobs acima de `max_download_size` com `BlobTooLargeException`.
Para arquivos grandes use `stream()` ou `downloadTo()`, que mantêm o uso de
memória constante.

### Enviar

```php
Blob::upload('a.txt', 'conteúdo');
Blob::uploadJson('dados.json', ['total' => 2]);
Blob::uploadFile('destino/a.pdf', '/tmp/local.pdf');

Blob::upload('a.pdf', $bytes, [
    'content_type' => 'application/pdf',   // detectado pela extensão se omitido
    'overwrite'    => false,               // falha se já existir
    'metadata'     => ['origem' => 'web'],
]);
```

Acima de `block_size` o envio é fatiado automaticamente em `Put Block` +
`Put Block List`, o que contorna o teto de 256 MiB do PUT simples.

### Remover, copiar e mover

```php
Blob::delete('a.pdf');                    // false se já não existia
Blob::deleteDirectory('2026/rascunhos');  // devolve a quantidade removida

Blob::copy('a.pdf', 'b.pdf', ['destination_container' => 'processados']);
Blob::move('origem.pdf', 'arquivo-morto/origem.pdf');
```

### URLs assinadas

```php
Blob::url('a.pdf');                                  // pública, sem token
Blob::temporaryUrl('a.pdf', 2, 'r');                 // 2 horas
Blob::temporaryUrl('a.pdf', now()->addDay(), 'rw');  // instante explícito
```

### Storage::disk

```php
// config/filesystems.php
'azure' => ['driver' => 'azure-blob', 'connection' => 'default'],
```

```php
Storage::disk('azure')->put('pasta/a.txt', $conteudo);
Storage::disk('azure')->temporaryUrl('pasta/a.txt', now()->addHour());

$request->file('anexo')->store('anexos', 'azure');
```

### Comandos Artisan

```bash
php artisan azure:info --check
php artisan azure:list 2026/ --max=50
php artisan azure:upload /tmp/a.pdf destino/a.pdf
php artisan azure:sas 2026/a.pdf --hours=2
```

## Referência da API

O README cobre o uso comum. Para o detalhe de cada parte:

| Documento | Conteúdo |
|---|---|
| [docs/api.md](docs/api.md) | `AzureBlob\BlobClient` e `AzureBlob\Results` — todos os métodos, suas opções e os objetos de retorno (`BlobList`, `BlobItem`, `BlobProperties`, `BlobContent`) |
| [docs/authentication.md](docs/authentication.md) | Os três modos de autenticação, como o Shared Key é assinado e por que a ordem das permissões do SAS importa |
| [docs/filesystem.md](docs/filesystem.md) | O driver `azure-blob`, os dois adaptadores de Flysystem e as diferenças de comportamento entre Laravel 8 e 9+ |
| [docs/commands.md](docs/commands.md) | Os oito comandos `azure:*`, suas opções e a regra de assinatura que o parser do Laravel 8 impõe |
| [docs/mcp-migration.md](docs/mcp-migration.md) | Equivalência com a ferramenta MCP em Python que este pacote substitui |
| [docs/php-8.0-compat.md](docs/php-8.0-compat.md) | O que o piso de PHP 8.0 custou e como reverter |

## Tratamento de erros

Todas as falhas lançam `AzureBlob\Exceptions\AzureBlobException`, que estende `\Exception`.

```php
use AzureBlob\Exceptions\AzureBlobException;
use AzureBlob\Exceptions\BlobNotFoundException;

try {
    $conteudo = Blob::download('a.pdf');
} catch (BlobNotFoundException $e) {
    // 404
} catch (AzureBlobException $e) {
    $e->getMessage();
    $e->status();      // 403
    $e->errorCode();   // 'AuthorizationPermissionMismatch'
    $e->context();     // dados já sanitizados
}
```

| Exceção | Quando |
|---|---|
| `ConfigurationException` | Credencial ausente, container indefinido, conexão inexistente |
| `BlobNotFoundException` | HTTP 404 do Azure |
| `BlobTooLargeException` | `download()` acima de `max_download_size` |
| `ReadOnlyException` | Escrita numa conexão travada; lançada antes de a requisição sair |
| `AzureBlobException` | Demais falhas HTTP e de comunicação |

Todas descendem de `AzureBlobException` — capture as específicas primeiro se
quiser distinguir os casos.

## Testes

```bash
composer install
composer test
```

> **Desenvolver o pacote exige PHP 8.1+**, embora ele *rode* em 8.0. O motivo é o
> `laravel/pint`, que exige 8.1. Para instalar as dependências de desenvolvimento em
> PHP 8.0, remova-o antes: `composer remove --dev laravel/pint --no-update`. É o que
> a linha de PHP 8.0 do CI faz.

265 testes, 614 asserções. Cobertura: **87,06% de linhas / 74,90% de métodos**
(medida com Xdebug em PHP 8.3 / Laravel 13).

Rodado localmente em três pontos da matriz antes da publicação:

| PHP | Laravel | Flysystem | PHPUnit | Resultado |
|---|---|---|---|---|
| 8.0.30 | 8.83 | 1.1 | 9.6 | 265 testes, 21 pulados |
| 8.2.33 | 10.50 | 3.35 | 10.5 | 265 testes |
| 8.3.33 | 13.25 | 3.35 | 12.5 | 265 testes |

Os 21 pulados no Laravel 8 são os que instanciam o adaptador de Flysystem 3
diretamente — lá esse caminho é coberto via `Storage::disk()`.

A suíte usa `Http::fake()` — nenhuma requisição sai para o Azure.

Para cobertura local:

```bash
vendor/bin/phpunit --coverage-text --coverage-filter src   # requer pcov ou xdebug
```

Nos testes da sua aplicação, faça o mesmo — o pacote inteiro passa pelo cliente
HTTP do Laravel, então `Http::fake()` cobre também o driver de Storage e os
comandos Artisan:

```php
Http::fake([
    '*/meu-container/a.txt' => Http::response('conteúdo', 200),
    '*' => Http::response('', 201),
]);

Blob::upload('a.txt', 'x');

Http::assertSent(fn ($request) => $request->method() === 'PUT');
```

## Corrigido durante a construção

Bugs reais encontrados pela suíte e pela validação na matriz, não hipóteses:

- **Upload em blocos nunca fatiava.** Vindo de uma string, o primeiro pedaço
  chegava vazio e o laço saía de cara: o commit ia com zero blocos e gravava um
  blob vazio. Só o caminho de stream funcionava.
- **`azure:delete` estava quebrado no Laravel 8.** A descrição do argumento
  continha `--recursive`, e o parser de assinatura do Laravel 8 casa
  `/-{2,}(.*)/` **sem âncora** — o argumento inteiro virava opção e o comando
  morria com `The "blob" argument does not exist`. Só o Laravel 9+ ancorou a
  expressão. `tests/Unit/CommandSignatureTest.php` guarda a regra.
- **`Content-Type` duplicado no Laravel 8.** `withHeaders()` e `withBody()`
  definiam o mesmo cabeçalho e o Guzzle os concatenava
  (`text/plain,text/plain`), valor que o Azure grava literal no blob.
- **`AzureBlobAdapterV1::copy()` e `rename()` devolviam array.** O contrato do
  Flysystem 1 pede `bool` nesses dois — só `write`/`update` devolvem array —, e
  `Storage::copy()` entregava o array ao chamador em vez de `true`.
- **SAS URL do Azurite era mal interpretada.** A heurística "host tem ponto →
  conta no subdomínio" dava falso positivo em `127.0.0.1` e descartava o
  endpoint. Passou a testar `FILTER_VALIDATE_IP`.
- **Nome do blob corrompido no contexto de erro.** Numa falha de operação de
  container (o caminho não tem barra), `strpos()` devolvia `false`, o cast para
  `int` virava `0` e o `substr` comia a primeira letra: o log dizia
  `eu-container`. Agora o campo fica vazio, que é o correto.
- **Dependências erradas no `composer.json`.** `symfony/http-foundation` foi
  herdado do pacote de referência e nunca usado; em compensação
  `illuminate/console`, `illuminate/filesystem` e `guzzlehttp/psr7` eram usados
  sem serem declarados.

## Pendências conhecidas

- **`AzureBlobAdapterV1` não é medido pela cobertura.** Sob o Flysystem 3 a
  classe sequer carrega, e a linha de PHP 8.0 não tem driver de cobertura
  disponível. Ela é exercitada por testes reais via `Storage::disk()` no CI do
  Laravel 8, mas não entra no percentual acima.
- **Não há suporte a `AZURE_PREFIX`.** A ferramenta MCP descobria variáveis por
  convenção de prefixo; aqui isso virou conexão nomeada explícita. Ver
  [docs/mcp-migration.md](docs/mcp-migration.md).
- **Sem suporte a Azure AD / Managed Identity.** Só Shared Key e SAS. Uma conta
  com `allowSharedKeyAccess=false` não funciona com este pacote.
- **Append blobs e page blobs não são suportados** — só block blobs, que é o que
  cobre armazenamento de arquivos.

## Apoie

Este pacote é mantido nas horas vagas. Se ele te poupou algumas horas de briga com
a REST API do Azure, [me paga um café](https://www.buymeacoffee.com/joshuabarbosa) ☕ — ajuda
a manter a compatibilidade em dia conforme o Laravel e a API do Azure evoluem.

Contribuição de código também é bem-vinda: abra uma
[issue](https://github.com/joshua-barbosa/azure-blob-sdk/issues) ou um PR.

## Licença

MIT.
