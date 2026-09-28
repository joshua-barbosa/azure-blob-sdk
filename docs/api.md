# Referência da API

`AzureBlob\BlobClient` — os verbos do SDK. Toda chamada aceita caminhos com ou
sem barra inicial: `Path::normalize()` colapsa barras duplicadas e remove a
inicial, então `/a//b.txt` e `a/b.txt` apontam para o mesmo blob.

## Conexão e container

| Método | Retorno | Observação |
|---|---|---|
| `config()` | `Support\Config` | Configuração resolvida da conexão |
| `containerName()` | `string` | Container atual |
| `container(?string)` | `BlobClient` | **Nova instância**; a original não muda. `null` devolve a mesma |
| `readOnly()` | `BlobClient` | Cópia com a trava de escrita ligada |
| `isReadOnly()` | `bool` | |
| `info()` | `string` | `Conta: x \| Container: y \| Auth: z` |

## Leitura

| Método | Retorno | Observação |
|---|---|---|
| `list(?prefix, maxResults = 100, options = [])` | `Results\BlobList` | Uma página. `maxResults` é limitado a 5000, o teto do Azure |
| `listAll(?prefix, options = [])` | `Generator<BlobItem>` | Segue o `NextMarker` sozinho; não carrega tudo na memória |
| `directory(path = '', maxResults)` | `Results\BlobList` | Listagem rasa: usa `delimiter=/`, então subpastas voltam em `->directories` |
| `files(path = '', recursive = false)` | `array<string>` | Nomes dos arquivos da pasta, todas as páginas. `recursive` inclui subpastas |
| `directories(path = '')` | `array<string>` | Subpastas imediatas, sem barra final |
| `listNames(?prefix, ?max = null)` | `array<string>` | Nomes sob o prefixo, todas as páginas; `max` limita o total |
| `download(blob, options = [])` | `Results\BlobContent` | Faz HEAD + GET. Recusa acima de `max_download_size` |
| `downloadJson(blob, associative = true)` | `mixed` | Sem checagem de tamanho |
| `get(blob)` | `string` | Bytes crus, sem checagem de tamanho |
| `downloadText(blob)` | `string` | O mesmo que `get()`; existe pela paridade com o SDK Node |
| `stream(blob)` | `resource` | Memória constante |
| `downloadTo(blob, path)` | `int` | Bytes gravados; usa stream. Cria a pasta de destino e grava via arquivo temporário: uma falha não destrói o arquivo existente |
| `properties(blob)` | `Results\BlobProperties` | HEAD |
| `exists(blob)` / `missing(blob)` | `bool` | 404 não lança |
| `size` / `lastModified` / `mimeType` | `int` / `?DateTimeInterface` / `?string` | Atalhos sobre `properties()` |

`options` de `list()`/`listAll()`: `marker`, `delimiter`, `include` (ex.: `metadata`).
`options` de `download()`: `max_size`, que sobrescreve o limite da conexão.

## URLs

| Método | Observação |
|---|---|
| `url(blob)` | URL pública, sem token. Só abre se o container for público |
| `temporaryUrl(blob, expiry = 1, permissions = 'r')` | `expiry` aceita horas (`int`) ou `DateTimeInterface` |
| `sasUrl(...)` | Alias de `temporaryUrl()`, com o nome usado pela ferramenta MCP |
| `temporaryContainerUrl(expiry = 1, permissions = 'rl')` | SAS do container |

No modo SAS URL o token do container é reaproveitado e `expiry`/`permissions`
são ignorados — ver [authentication.md](authentication.md).

## Container

| Método | Retorno | Observação |
|---|---|---|
| `containerExists()` | `bool` | No modo SAS URL usa uma listagem de 1 item: SAS de container não autoriza Get Container Properties |
| `ensureContainer()` | `bool` | Cria quando falta; `true` se criou, `false` se já existia. Exige chave da conta ou SAS de conta |

## Escrita

| Método | Retorno | Observação |
|---|---|---|
| `upload(blob, contents, options = [])` | `string` (URL) | `contents` aceita `string` ou `resource` |
| `uploadJson(blob, data, options = [])` | `string` | `JSON_UNESCAPED_UNICODE`, acentos não viram `\uXXXX` |
| `uploadFile(blob, path, options = [])` | `string` | Content-Type detectado pela extensão |
| `setMetadata(blob, array)` | `bool` | **Substitui** os metadados |
| `delete(blob)` | `bool` | `false` quando já não existia |
| `deleteDirectory(prefix)` | `int` | Quantidade removida. Prefixo vazio é recusado |
| `copy(source, dest, options = [])` | `string` (URL) | |
| `move(source, dest, options = [])` | `string` (URL) | Copia com `wait`, depois apaga a origem. Recusa origem igual ao destino |

`options` de upload: `content_type`, `overwrite` (padrão `true`), `metadata`,
`cache_control`, `content_disposition`, `content_encoding`, `content_language`.

`options` de copy/move: `source_container`, `destination_container`, `wait`, `timeout`.

**Upload em blocos.** Conteúdo acima de `block_size` (4 MiB) vai em `Put Block` +
`Put Block List` em vez de um PUT único — isso contorna o teto de 256 MiB do PUT
simples e mantém o uso de memória constante. Os ids de bloco têm todos o mesmo
comprimento, exigência do Azure (`InvalidBlockList` caso contrário).

**`overwrite => false`** vira `If-None-Match: *`, e um blob existente devolve 409
`BlobAlreadyExists`.

**Metadados** viram cabeçalhos `x-ms-meta-*`. Nomes são normalizados para
identificadores C# (só letras, dígitos e `_`, sem começar com dígito); nomes
inválidos são descartados em vez de enviados quebrados.

**Cópia é assíncrona no Azure** quando envolve containers diferentes ou blobs
grandes. `copy()` não espera por padrão; `move()` sempre espera, senão poderia
apagar a origem antes de ela ser lida por completo. Uma cópia que termina
`failed` ou `aborted` lança `AzureBlobException` — e o `move()` não apaga a
origem.

**Propriedades de conteúdo** (`cache_control`, `content_disposition`,
`content_encoding`, `content_language`) vão como `x-ms-blob-*`. Como cabeçalho
padrão o Azure as ignora em silêncio no `Put Blob`.

## Objetos de resultado

`AzureBlob\Results` — todos implementam `JsonSerializable` e expõem `toArray()`.

| Classe | Conteúdo |
|---|---|
| `BlobList` | `items`, `directories`, `nextMarker`, `prefix`, `container`. `Countable` e `IteratorAggregate` sobre `items`; `collect()` devolve uma `Collection` |
| `BlobItem` | `name`, `size`, `contentType`, `lastModified`, `createdOn`, `etag`, `blobType`, `url`, `isDirectory`. Mais `basename()`, `dirname()`, `humanSize()` |
| `BlobProperties` | Tudo do `BlobItem` mais `contentMd5`, `cacheControl`, `contentDisposition`, `accessTier` e `metadata` |
| `BlobContent` | `contents()` (bytes crus), `json()`, `base64()`, `saveTo()`, `isText()`, `encoding()`. `Stringable` |
| `Size` | `Size::human(int)` → `2.5 GB` |

`BlobContent::isText()` só devolve `true` quando o tipo é textual **e** os bytes
são UTF-8 válidos — arquivos rotulados `text/plain` em latin-1 são comuns, e
mandá-los como texto quebraria o JSON de quem serializa a resposta.
