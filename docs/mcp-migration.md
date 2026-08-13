# Migração da ferramenta MCP `azure`

Este pacote substitui o servidor MCP em Python (`server.py`) que expunha o Azure
Blob Storage como ferramentas MCP. O comportamento é o mesmo; muda o meio.

## Equivalência das ferramentas

| Ferramenta MCP | Equivalente no pacote | Comando Artisan |
| --- | --- | --- |
| `blob_list` | `Blob::list($prefix, $max)` | `azure:list` |
| `blob_download` | `Blob::download($nome)` | `azure:download` |
| `blob_download_json` | `Blob::downloadJson($nome)` | `azure:download` |
| `blob_get_properties` | `Blob::properties($nome)` | `azure:exists` |
| `blob_exists` | `Blob::exists($nome)` | `azure:exists` |
| `blob_get_url` | `Blob::url($nome)` | — |
| `blob_generate_sas_url` | `Blob::temporaryUrl($nome, $horas, $perm)` | `azure:sas` |
| `blob_upload` | `Blob::upload($nome, $conteudo)` | `azure:upload` |
| `blob_upload_json` | `Blob::uploadJson($nome, $dados)` | `azure:upload` |
| `blob_delete` | `Blob::delete($nome)` | `azure:delete` |
| `blob_copy` | `Blob::copy($origem, $destino)` | `azure:copy` |
| — (sem equivalente) | `Blob::info()` | `azure:info` |

## Variáveis de ambiente

Os nomes foram mantidos, então um `.env` existente continua valendo:

| MCP | Pacote |
| --- | --- |
| `AZURE_STORAGE_SAS_URL` | idem |
| `AZURE_STORAGE_CONNECTION_STRING` | idem |
| `AZURE_STORAGE_NAME` / `AZURE_STORAGE_KEY` | idem |
| `AZURE_STORAGE_CONTAINER` | idem |
| `AZURE_STORAGE_URL` | idem |
| `AZURE_READONLY` | idem |

### `AZURE_PREFIX` virou conexões nomeadas

No MCP, `AZURE_PREFIX=APOSTILAS` fazia o servidor ler
`APOSTILAS_AZURE_STORAGE_SAS_URL`. O prefixo era necessário porque cada processo
MCP atendia uma conta só.

Aqui uma aplicação fala com várias contas ao mesmo tempo, então o prefixo dá
lugar a conexões nomeadas explícitas em `config/azure-blob.php`:

```php
'connections' => [
    'apostilas' => [
        'sas_url' => env('APOSTILAS_AZURE_STORAGE_SAS_URL'),
    ],
    'contratos' => [
        'sas_url' => env('CONTRATOS_AZURE_STORAGE_SAS_URL'),
    ],
],
```

```php
Blob::connection('apostilas')->list();
```

O `.env` não precisa mudar: as variáveis prefixadas continuam com o mesmo nome,
só que agora são referenciadas no arquivo de config em vez de descobertas por
convenção.

## Diferenças de comportamento

**Conteúdo binário.** O MCP devolvia `{"content": ..., "encoding": "text|base64"}`
porque JSON não transporta bytes. Aqui `download()` devolve um `BlobContent` com
os bytes crus em `contents()`. O formato antigo continua disponível em
`toArray()`, para quem serializa a resposta.

**Limite de download.** O MCP recusava blobs acima de 5 MB. O pacote mantém o
padrão, agora configurável em `max_download_size`, e acrescenta `stream()` e
`downloadTo()` para arquivos grandes — que o MCP não tinha como oferecer.

**Upload em blocos.** O MCP fazia sempre um PUT único, o que estourava em
arquivos acima de 256 MiB. O pacote fatia automaticamente acima de `block_size`.

**Erros.** O MCP devolvia texto. O pacote lança exceções tipadas com `status()`,
`errorCode()` e `context()` — ver a seção de tratamento de erros do README.

**Cópia.** Ambos assinam um SAS para a origem, porque o serviço do Azure lê o
blob de origem por conta própria. O pacote acrescenta `wait`, que aguarda a
conclusão de cópias assíncronas (relevante entre containers e para blobs
grandes), e `move()`, que só apaga a origem depois disso.
