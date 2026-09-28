# Migração do `azure.py` (vetorizador)

Os dois SDKs deste repositório nasceram do módulo
`scripts/apostilas/vetorizador/functions/azure.py`, que envolve o
`azure-storage-blob` em funções `azure_*`. Esta tabela mostra o equivalente de
cada uma em PHP (Laravel) e em Node/TypeScript.

Nos exemplos, `AzureBlob` é a facade do Laravel e `azure` é um `BlobManager` do
Node (`createAzureBlob()`). No Node todos os métodos que falam com o Azure
devolvem `Promise`.

| Python | PHP / Laravel | Node / TypeScript |
|---|---|---|
| `azure_upload_blob(nome, dados, container, overwrite, content_type)` | `AzureBlob::container($c)->upload($nome, $dados, ['overwrite' => ..., 'content_type' => ...])` | `azure.container(c).upload(nome, dados, { overwrite, contentType })` |
| `azure_upload_file(caminho, nome)` | `AzureBlob::uploadFile($nome, $caminho)` | `azure.uploadFile(nome, caminho)` |
| `azure_download_blob(nome)` | `AzureBlob::get($nome)` | `azure.get(nome)` (Buffer) |
| `azure_download_blob_to_file(nome, caminho)` | `AzureBlob::downloadTo($nome, $caminho)` | `azure.downloadTo(nome, caminho)` |
| `azure_download_text(nome)` | `AzureBlob::downloadText($nome)` | `azure.downloadText(nome)` |
| `azure_download_json(nome)` | `AzureBlob::downloadJson($nome)` | `azure.downloadJson(nome)` |
| `azure_upload_json(nome, dados)` | `AzureBlob::uploadJson($nome, $dados)` | `azure.uploadJson(nome, dados)` |
| `azure_blob_exists(nome)` | `AzureBlob::exists($nome)` | `azure.exists(nome)` |
| `azure_delete_blob(nome)` | `AzureBlob::delete($nome)` | `azure.delete(nome)` |
| `azure_list_blobs(prefixo)` (gerador de dicts) | `AzureBlob::listAll($prefixo)` (gerador de `BlobItem`) | `azure.listAll(prefixo)` (`for await`) |
| `azure_list_blobs_names(prefixo, max_results=n)` | `AzureBlob::listNames($prefixo, $n)` | `azure.listNames(prefixo, n)` |
| `azure_get_blob_url(nome)` | `AzureBlob::url($nome)` | `azure.url(nome)` |
| `azure_get_blob_properties(nome)` | `AzureBlob::properties($nome)` | `azure.properties(nome)` |
| `azure_copy_blob(origem, destino, c_origem, c_destino)` | `AzureBlob::copy($o, $d, ['source_container' => ..., 'destination_container' => ...])` | `azure.copy(o, d, { sourceContainer, destinationContainer })` |
| `azure_ensure_container_exists(container)` | `AzureBlob::container($c)->ensureContainer()` | `azure.container(c).ensureContainer()` |
| `azure_get_container_client(c)` / `azure_get_blob_client(...)` | `AzureBlob::container($c)` | `azure.container(c)` |

## Além do que o `azure.py` fazia

- **Pastas:** `files($pasta)`, `files($pasta, recursive: true)` e
  `directories($pasta)` leem uma "pasta" sem despejar a árvore inteira.
- **URL temporária:** `temporaryUrl($nome, $horas)`. Com a chave da conta
  assina um SAS novo; com SAS URL devolve a URL com o token do container.
- **Arquivos grandes:** `stream()`, e upload em blocos acima de 4 MB. O
  `azure_download_blob_to_file` carregava o arquivo inteiro na memória antes de
  gravar.
- **Várias contas ao mesmo tempo**, por nome de conexão.
- **Erros tipados** (`BlobNotFound…`, `BlobTooLarge…`, `ReadOnly…`), com o
  código do Azure.

## Diferenças de comportamento

- **Pasta de destino:** o `downloadTo` cria a pasta que falta, como o Python.
  Ele também grava num arquivo temporário e só renomeia no fim, então uma falha
  não destrói o arquivo que já existia.
- **Cópia:** o `azure_copy_blob` não esperava a cópia terminar. O `copy()`
  também não espera, a menos que você passe `wait`. O `move()` sempre espera e
  falha, sem apagar a origem, se a cópia terminar com erro.
- **Container em modo SAS URL:** o `ensureContainer()` precisa da chave da conta
  ou de um SAS de conta. Um SAS de container, o modo do `azure.py`, não pode
  criar containers — no Python a chamada também falharia. Já o
  `containerExists()` funciona nesse modo.
- **URL em `list`:** o Python montava a URL do blob sem codificar o nome. Os
  SDKs codificam cada segmento, e nomes com espaço, `#` ou `%` continuam
  abrindo.
