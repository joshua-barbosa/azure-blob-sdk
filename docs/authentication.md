# Autenticação

`AzureBlob\Support` — como cada modo é resolvido e o que muda entre eles.

A precedência é **SAS URL > connection string > conta + chave**, decidida em
`Config::fromArray()`. Definir mais de um modo não é erro: o de maior
precedência vence, e os demais só preenchem campos que faltarem (o `name`
explícito continua valendo mesmo com `sas_url`, por exemplo).

## Modo 1 — SAS URL

```php
'sas_url' => 'https://conta.blob.core.windows.net/meu-container?sp=racwdl&se=...&sig=...',
```

`SasUrl::parse()` quebra a URL em endpoint da conta, container e token. Nenhuma
requisição é assinada: o token viaja na query string, concatenado **cru** — o
`sig` é base64 já percent-encoded, e reencodá-lo invalidaria a assinatura.

É o modo recomendado. O token tem escopo, permissões e validade definidos na
emissão, e a chave da conta nunca entra na aplicação.

**Limitação:** sem a chave, não há como assinar um SAS novo. `temporaryUrl()`
reaproveita o token do container e ignora expiração e permissões pedidas. Se
você precisa de URLs com validade sob controle da aplicação, use o modo 2 ou 3.

Aceita também o formato de emulador, com a conta no path
(`http://127.0.0.1:10000/devstoreaccount1/container?...`). A distinção é feita
por `filter_var(..., FILTER_VALIDATE_IP)`, não por presença de ponto — um IP tem
pontos e cairia no caso errado.

## Modo 2 — Connection string

```php
'connection_string' => 'DefaultEndpointsProtocol=https;AccountName=conta;AccountKey=...;EndpointSuffix=core.windows.net',
'container' => 'meu-container',
```

`ConnectionString::parse()` aceita `AccountName`/`AccountKey`, `BlobEndpoint`
explícito (domínio próprio, Azurite) e `SharedAccessSignature`. O split preserva
o `=` interno: a `AccountKey` é base64 e quase sempre termina em `=`, e um
`explode('=')` ingênuo cortaria a chave — toda assinatura sairia errada.

Uma connection string que traga só `SharedAccessSignature`, sem `AccountKey`,
cai no modo 1.

## Modo 3 — Conta + chave

```php
'name' => 'conta',
'key'  => 'base64DaChaveDaConta==',
'container' => 'meu-container',
```

Endpoint derivado como `https://{name}.blob.{endpoint_suffix}`.

## Assinatura Shared Key

`SharedKeySigner` implementa o esquema completo. O string-to-sign é
**posicional** — cabeçalho fora de ordem, espaço sobrando ou parâmetro de query
esquecido devolve `403 AuthenticationFailed` e nada mais específico:

```
VERB\n
Content-Encoding\n Content-Language\n Content-Length\n Content-MD5\n
Content-Type\n Date\n If-Modified-Since\n If-Match\n If-None-Match\n
If-Unmodified-Since\n Range\n
CanonicalizedHeaders
CanonicalizedResource
```

Detalhes que costumam passar batido e estão cobertos por teste:

- `Date` fica **vazio** porque usamos `x-ms-date`, que tem precedência.
- `Content-Length` igual a zero entra como **string vazia**, não `"0"`
  (comportamento exigido desde a versão 2015-02-21 da API).
- Cabeçalhos `x-ms-*` vão em minúsculas, ordenados lexicograficamente, com
  espaços internos colapsados.
- O recurso canônico lista os parâmetros de query em minúsculas, ordenados, com
  valores repetidos unidos por vírgula e **decodificados**.

## Service SAS

`SasBuilder` assina SAS de blob (`sr=b`) ou de container (`sr=c`). O layout do
string-to-sign vale para `sv >= 2020-12-06`; versões anteriores têm menos campos
e produziriam assinatura inválida.

A string de permissões (`sp`) é **posicional**: `rw` é aceito, `wr` devolve
`AuthenticationFailed`. `SasBuilder::normalizePermissions()` reordena, deduplica
e descarta letras desconhecidas, então `'dwcar'` vira `'racwd'` sozinho.

Ordem canônica: `r a c w d x y l t f m e o p i`.

## Somente leitura

`readonly => true` faz `BlobClient` lançar `ReadOnlyException` em `upload`,
`delete`, `copy`, `move`, `deleteDirectory` e `setMetadata` **antes** de qualquer
requisição sair. É proteção contra engano — não substitui as permissões do SAS
nem o RBAC da conta, que continuam sendo a fronteira real.

`$client->readOnly()` devolve uma cópia travada, útil para passar a um trecho de
código que só deveria ler.
