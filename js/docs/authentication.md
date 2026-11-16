# Autenticação

Como cada modo é resolvido e o que muda entre eles.

A precedência é **SAS URL > connection string > conta + chave**, decidida em
`Config.fromOptions()`. Definir mais de um modo não é erro: o de maior
precedência vence. As chaves aceitam camelCase (`sasUrl`) e snake_case
(`sas_url`), o formato do `config/azure-blob.php` do SDK PHP.

## Modo 1 — SAS URL

```ts
{ sasUrl: 'https://conta.blob.core.windows.net/meu-container?sp=racwdl&se=...&sig=...' }
```

A URL é quebrada em endpoint da conta, container e token. Nenhuma requisição é
assinada: o token viaja na query string, concatenado **cru** — o `sig` é base64
já percent-encoded, e reencodá-lo invalidaria a assinatura.

É o modo recomendado. O token tem escopo, permissões e validade definidos na
emissão, e a chave da conta nunca entra na aplicação.

**Limitação:** sem a chave, não há como assinar um SAS novo. `temporaryUrl()`
reaproveita o token do container e ignora expiração e permissões pedidas. Se
você precisa de URLs com validade sob controle da aplicação, use o modo 2 ou 3.

Aceita também o formato de emulador, com a conta no path
(`http://127.0.0.1:10000/devstoreaccount1/container?...`). A distinção é feita
por `isIP()`, não por presença de ponto — um IP tem pontos e cairia no caso
errado.

## Modo 2 — Connection string

```ts
{
    connectionString: 'DefaultEndpointsProtocol=https;AccountName=conta;AccountKey=...;EndpointSuffix=core.windows.net',
    container: 'meu-container',
}
```

Aceita `AccountName`/`AccountKey`, `BlobEndpoint` explícito (domínio próprio,
Azurite) e `SharedAccessSignature`. Uma connection string que traga só
`SharedAccessSignature`, sem `AccountKey`, cai no modo 1.

## Modo 3 — Conta + chave

```ts
{ name: 'conta', key: 'base64DaChaveDaConta==', container: 'meu-container' }
```

Endpoint derivado como `https://{name}.blob.{endpointSuffix}`.

## Assinatura Shared Key

`SharedKeySigner` implementa o esquema completo com `node:crypto`. O
string-to-sign é **posicional** — cabeçalho fora de ordem, espaço sobrando ou
parâmetro de query esquecido devolve `403 AuthenticationFailed` e nada mais
específico:

```
VERB\n
Content-Encoding\n Content-Language\n Content-Length\n Content-MD5\n
Content-Type\n Date\n If-Modified-Since\n If-Match\n If-None-Match\n
If-Unmodified-Since\n Range\n
CanonicalizedHeaders
CanonicalizedResource
```

- `Date` fica **vazio** porque usamos `x-ms-date`, que tem precedência.
- `Content-Length` igual a zero entra como **string vazia**, não `"0"`.
- Cabeçalhos `x-ms-*` vão em minúsculas, ordenados, com espaços internos
  colapsados.
- O recurso canônico lista os parâmetros de query em minúsculas, ordenados, com
  valores repetidos unidos por vírgula e **decodificados**.

As assinaturas são idênticas, byte a byte, às do SDK PHP:
`test/unit/signing.test.ts` compara com vetores gerados por ele.

## Service SAS

`SasBuilder` assina SAS de blob (`sr=b`) ou de container (`sr=c`). O layout do
string-to-sign vale para `sv >= 2020-12-06`.

A string de permissões (`sp`) é **posicional**: `rw` é aceito, `wr` devolve
`AuthenticationFailed`. `normalizePermissions()` reordena, deduplica e descarta
letras desconhecidas, então `'dwcar'` vira `'racwd'` sozinho.

Ordem canônica: `r a c w d x y l t f m e o p i`.

**Protocolo (`spr`).** Quando o endpoint da conta é `https://`, o SAS sai com
`spr=https` e o Azure recusa o link em http (`AuthorizationProtocolMismatch`).
O valor entra no string-to-sign (campo `signedProtocol`), então não dá para
retirá-lo da URL depois. Num endpoint `http://` — o Azurite — o campo é
omitido, o que o Azure lê como `https,http`; com a restrição o emulador
recusaria o próprio link.

**Cabeçalhos de resposta.** `temporaryUrl(blob, expiry, permissions, options)`
aceita `contentType` (`rsct`) e `contentDisposition` (`rscd`), além de `start`
(`st`) e `ip` (`sip`). Todos entram na assinatura.

## Identificadores de rastreio

Toda requisição leva um `x-ms-client-request-id` com um UUID novo
(`crypto.randomUUID()`); se o chamador já mandar esse cabeçalho, o valor dele é
mantido. No modo Shared Key ele é assinado junto com os demais `x-ms-*`.

Quando o Azure responde com erro, o `AzureBlobError` e o log guardam os dois
ids:

| Contexto | Propriedade | Origem |
|---|---|---|
| `request_id` | `error.requestId` | `x-ms-request-id` da resposta; `null` se o Azure não o devolveu |
| `client_request_id` | `error.clientRequestId` | o `x-ms-client-request-id` enviado |

São os dois valores que o suporte da Microsoft pede para localizar uma
requisição, e o `client_request_id` também aparece nos logs de diagnóstico da
conta. Numa falha de comunicação (sem resposta) só existe o `clientRequestId`.

O SDK não refaz tentativas: repetir após `503 ServerBusy`, `500` ou queda de
conexão fica a cargo da aplicação.

## Somente leitura

`readonly: true` faz o `BlobClient` lançar `ReadOnlyError` em `upload`,
`delete`, `copy`, `move`, `deleteDirectory`, `setMetadata` e `ensureContainer`
**antes** de qualquer requisição sair. É proteção contra engano — não substitui
as permissões do SAS nem o RBAC da conta, que continuam sendo a fronteira real.

`client.readOnly()` devolve uma cópia travada, útil para passar a um trecho de
código que só deveria ler.

## Credenciais nos logs

SAS tokens, chaves e assinaturas são mascarados (`[REDACTED]`) no contexto dos
erros e no que vai para o `logger`. O `logger` é opcional e o SDK é silencioso
por padrão; qualquer objeto com `warn(message, context)` serve, inclusive
`console`.
