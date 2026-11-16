# Referência da API

`BlobClient` — os verbos do SDK. Toda chamada aceita caminhos com ou sem barra
inicial: `/a//b.txt` e `a/b.txt` apontam para o mesmo blob. Segmentos `.` e `..`
são recusados, porque o parser de URL do `fetch` resolveria `a/../b` para `b`.

Os mesmos métodos existem no `BlobManager`, onde vão para a conexão padrão:
`azure.list()` equivale a `azure.connection().list()`.

## Gerenciador

| Método | Retorno | Observação |
|---|---|---|
| `new BlobManager(options)` | `BlobManager` | `default`, `connections`, `http`, `logger` |
| `BlobManager.fromEnv(env?, overrides?)` | `BlobManager` | Lê as variáveis `AZURE_*`; `overrides` completa ou sobrescreve |
| `createAzureBlob(options?)` | `BlobManager` | Atalho: sem opções, o mesmo que `fromEnv()` |
| `connection(name?)` / `disk(name?)` | `BlobClient` | Resolvido sob demanda e mantido em cache |
| `build(connection, name = 'ad-hoc')` | `BlobClient` | Credencial avulsa, sem registrar a conexão |
| `purge(name?)` | `void` | Descarta clientes em cache |
| `defaultConnection()` / `connectionNames()` | `string` / `string[]` | |

## Conexão e container

| Método | Retorno | Observação |
|---|---|---|
| `config()` | `Config` | Configuração resolvida da conexão |
| `containerName()` | `string` | Container atual |
| `container(name)` | `BlobClient` | **Nova instância**; a original não muda. `null` devolve a mesma |
| `readOnly()` | `BlobClient` | Cópia com a trava de escrita ligada |
| `isReadOnly()` | `boolean` | |
| `info()` | `string` | `Conta: x \| Container: y \| Auth: z` |

## Leitura

| Método | Retorno | Observação |
|---|---|---|
| `list(prefix?, maxResults = 100, options?)` | `Promise<BlobList>` | Uma página. `maxResults` é limitado a 5000, o teto do Azure |
| `listAll(prefix?, options?)` | `AsyncGenerator<BlobItem>` | Segue o `NextMarker` sozinho; não carrega tudo na memória |
| `directory(path = '', maxResults?)` | `Promise<BlobList>` | Listagem rasa: usa `delimiter=/`, então subpastas voltam em `directories` |
| `files(path = '', recursive = false)` | `Promise<string[]>` | Nomes dos arquivos da pasta, todas as páginas |
| `directories(path = '')` | `Promise<string[]>` | Subpastas imediatas, sem barra final |
| `listNames(prefix?, max?)` | `Promise<string[]>` | Nomes sob o prefixo, todas as páginas; `max` limita o total |
| `download(blob, options?)` | `Promise<BlobContent>` | Faz HEAD + GET. Recusa acima de `maxDownloadSize` |
| `downloadJson<T>(blob)` | `Promise<T>` | Sem checagem de tamanho |
| `downloadText(blob)` | `Promise<string>` | UTF-8, sem checagem de tamanho |
| `get(blob)` | `Promise<Buffer>` | Bytes, sem checagem de tamanho |
| `stream(blob)` | `Promise<Readable>` | Memória constante |
| `downloadTo(blob, path)` | `Promise<number>` | Bytes gravados; usa stream e cria a pasta de destino |
| `properties(blob)` | `Promise<BlobProperties>` | HEAD |
| `exists(blob)` / `missing(blob)` | `Promise<boolean>` | 404 não lança |
| `size` / `lastModified` / `mimeType` | `number` / `Date \| null` / `string \| null` | Atalhos sobre `properties()` |

`options` de `list()`/`listAll()`: `marker`, `delimiter`, `include` (ex.: `metadata`).
`options` de `download()`: `maxSize`, que sobrescreve o limite da conexão.

Um blob gravado com `contentEncoding` `gzip`, `br` ou `deflate` chega **já
descomprimido**: o `fetch` nativo decodifica o corpo e não permite desligar isso.

## URLs

| Método | Observação |
|---|---|
| `url(blob)` | URL pública, sem token. Só abre se o container for público |
| `temporaryUrl(blob, expiry = 1, permissions = 'r', options?)` | `expiry` aceita horas (`number`, piso de 1) ou `Date` |
| `sasUrl(blob, expiry, permissions)` | Alias de `temporaryUrl()` |
| `temporaryContainerUrl(expiry = 1, permissions = 'rl')` | SAS do container |

`options` de `temporaryUrl()`: `start`, `ip`, `contentType` (`rsct`) e
`contentDisposition` (`rscd`), que sobrescrevem os cabeçalhos devolvidos na
leitura do link.

No modo SAS URL o token do container é reaproveitado e `expiry`, `permissions`
e `options` são ignorados — ver [authentication.md](authentication.md).

Em conta `https://` o SAS novo sai com `spr=https` e não funciona em http.

## Erros

Toda falha é um `AzureBlobError` (ou subclasse):

| Propriedade | Devolve |
|---|---|
| `status` | Status HTTP, quando houve resposta |
| `errorCode` | Código do Azure, ex.: `BlobNotFound` |
| `requestId` | `x-ms-request-id` da resposta |
| `clientRequestId` | `x-ms-client-request-id` enviado pelo SDK |
| `context` | Todos os dados acima e mais, já sem credenciais |
| `cause` | O erro original, numa falha de rede ou de disco |

| Classe | Quando |
|---|---|
| `BlobNotFoundError` | 404: blob ou container inexistente |
| `BlobTooLargeError` | `download()` acima de `maxDownloadSize` |
| `ConfigurationError` | Credencial, container ou conexão faltando |
| `ReadOnlyError` | Escrita numa conexão `readonly` |

Não há retry automático — ver
[authentication.md](authentication.md#identificadores-de-rastreio).

## Container

| Método | Retorno | Observação |
|---|---|---|
| `containerExists()` | `Promise<boolean>` | No modo SAS URL usa uma listagem de 1 item: SAS de container não autoriza Get Container Properties |
| `ensureContainer()` | `Promise<boolean>` | Cria quando falta; `true` se criou, `false` se já existia. Exige chave da conta ou SAS de conta |

## Escrita

| Método | Retorno | Observação |
|---|---|---|
| `upload(blob, contents, options?)` | `Promise<string>` (URL) | `string`, `Uint8Array`/`Buffer`, `ArrayBuffer`, `Readable`, `ReadableStream` web ou iterável assíncrono |
| `uploadJson(blob, data, options?)` | `Promise<string>` | Acentos não viram `\uXXXX` |
| `uploadFile(blob, path, options?)` | `Promise<string>` | Content-Type detectado pela extensão |
| `setMetadata(blob, metadata)` | `Promise<true>` | **Substitui** os metadados |
| `delete(blob)` | `Promise<boolean>` | `false` quando já não existia |
| `deleteDirectory(prefix)` | `Promise<number>` | Quantidade removida. Prefixo vazio é recusado |
| `copy(source, dest, options?)` | `Promise<string>` (URL) | |
| `move(source, dest, options?)` | `Promise<string>` (URL) | Copia com `wait`, depois apaga a origem. Recusa origem igual ao destino |

`options` de upload: `contentType`, `overwrite` (padrão `true`), `metadata`,
`cacheControl`, `contentDisposition`, `contentEncoding`, `contentLanguage`.

`options` de copy/move: `sourceContainer`, `destinationContainer`, `wait`,
`timeout` (segundos de espera, padrão 30).

**Upload em blocos.** Conteúdo acima de `blockSize` (4 MiB) vai em `Put Block` +
`Put Block List` em vez de um PUT único — isso contorna o teto de 256 MiB do PUT
simples e mantém o uso de memória constante, inclusive para streams de tamanho
desconhecido.

**`overwrite: false`** vira `If-None-Match: *`, e um blob existente devolve 409
`BlobAlreadyExists`.

**Metadados** viram cabeçalhos `x-ms-meta-*`. Nomes são normalizados para
identificadores (só letras, dígitos e `_`, sem começar com dígito).

**Cópia é assíncrona no Azure** quando envolve containers diferentes ou blobs
grandes. `copy()` não espera por padrão; `move()` sempre espera. Uma cópia que
termina `failed` ou `aborted` lança `AzureBlobError` — e o `move()` não apaga a
origem.

**Propriedades de conteúdo** (`cacheControl`, `contentDisposition`,
`contentEncoding`, `contentLanguage`) vão como `x-ms-blob-*`.

## Objetos de resultado

Todos são imutáveis e têm `toJSON()`, que produz o mesmo formato do `toArray()`
do SDK PHP (snake_case, datas ATOM).

| Classe | Conteúdo |
|---|---|
| `BlobList` | `items`, `directories`, `nextMarker`, `prefix`, `container`. Iterável sobre `items`; `all()`, `names()`, `totalSize()`, `hasMore()`, `isEmpty()`, `length` |
| `BlobItem` | `name`, `size`, `contentType`, `lastModified`, `createdOn`, `etag`, `contentMd5`, `blobType`, `url`, `isDirectory`. Mais `basename()`, `dirname()`, `humanSize()` |
| `BlobProperties` | `name`, `container`, `size`, `contentType`, `contentMd5`, `contentEncoding`, `cacheControl`, `contentDisposition`, `lastModified`, `createdOn`, `etag`, `blobType`, `accessTier`, `metadata`, `url` |
| `BlobContent` | `contents` (`Buffer`), `name`, `contentType`, `size`, `properties`. Mais `text()`, `json<T>()`, `base64()`, `saveTo()`, `isText()`, `encoding()` |

`BlobContent.isText()` só devolve `true` quando o tipo é textual **e** os bytes
são UTF-8 válidos — arquivos rotulados `text/plain` em latin-1 são comuns.

Também são exportados `humanSize()`, `guessMimeType()`, os utilitários de caminho
(`normalize`, `encode`, `basename`, `dirname`, `directoryPrefix`), `scrub()` /
`scrubUrl()` e as classes `SasBuilder` e `SharedKeySigner`.
