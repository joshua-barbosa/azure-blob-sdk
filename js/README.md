# Azure Blob SDK — Node.js / TypeScript

[![npm](https://img.shields.io/npm/v/@joshualevy029/azure-blob-sdk.svg)](https://www.npmjs.com/package/@joshualevy029/azure-blob-sdk)
[![Tests](https://github.com/joshua-barbosa/azure-blob-sdk/actions/workflows/node.yml/badge.svg)](https://github.com/joshua-barbosa/azure-blob-sdk/actions/workflows/node.yml)
[![Node](https://img.shields.io/node/v/@joshualevy029/azure-blob-sdk.svg)](https://www.npmjs.com/package/@joshualevy029/azure-blob-sdk)
[![License](https://img.shields.io/npm/l/@joshualevy029/azure-blob-sdk.svg)](LICENSE)
[![Buy Me A Coffee](https://img.shields.io/badge/buy%20me%20a%20coffee-%E2%98%95-FFDD00)](https://www.buymeacoffee.com/joshuabarbosa)

SDK Node/TypeScript para o [Azure Blob Storage](https://learn.microsoft.com/rest/api/storageservices/blob-service-rest-api).
É a versão JavaScript do [SDK PHP/Laravel](../README.md) deste mesmo repositório: mesma API, mesmas
variáveis de ambiente, mesmo formato de JSON.

**Zero dependências de runtime.** A REST API do Azure é implementada direto sobre o `fetch` nativo,
com assinatura **Shared Key** (HMAC-SHA256) e geração de **Service SAS** pelo `node:crypto`. As
assinaturas são conferidas byte a byte contra vetores gerados pelo SDK PHP, e a suíte roda contra o
[Azurite](https://github.com/Azure/Azurite), que valida as assinaturas como o Azure.

```ts
import { createAzureBlob } from '@joshualevy029/azure-blob-sdk';

const azure = createAzureBlob(); // lê AZURE_STORAGE_* do ambiente

await azure.list('apostilas/2026/');
await azure.downloadTo('apostilas/2026/matematica.pdf', '/tmp/mat.pdf');
await azure.upload('apostilas/2026/nova.pdf', conteudo);
azure.temporaryUrl('apostilas/2026/matematica.pdf', 2);

await azure.connection('contratos').uploadJson('metadados.json', dados);
```

- **Três modos de autenticação**: SAS URL, connection string ou conta + chave
- **Múltiplas conexões**, selecionáveis por nome
- **Upload em blocos** acima de 4 MB, com memória constante, para strings, buffers e streams
- **Streams** para download e upload de arquivos grandes
- **CLI** `azure-blob`, equivalente aos comandos `azure:*` do Artisan
- **Integrações**: módulo **NestJS** e driver **FlyDrive** (o Drive do AdonisJS)
- **ESM e CommonJS**, com tipos

> **Vem do `azure.py` do vetorizador?** A tabela de equivalência função a função está em
> [docs/python-migration.md](https://github.com/joshua-barbosa/azure-blob-sdk/blob/main/docs/python-migration.md).

## Compatibilidade

| Node | Situação |
|---|---|
| 24.x · 26.x | ✅ Recomendado |
| 22.x | ✅ Suportado |
| 20.19+ | ⚠️ Fora do suporte oficial do Node desde abril de 2026; suporte a ser removido |

| Integração | Versões |
|---|---|
| `@nestjs/common` | 10, 11, 12 |
| `flydrive` | 2.x (que por sua vez exige Node 24+) |

As integrações são *peer dependencies* opcionais: só são necessárias se você importar
`/nestjs` ou `/flydrive`.

## Instalação

```bash
npm install @joshualevy029/azure-blob-sdk
```

> O pacote está em `0.x`: a API pública ainda pode mudar entre versões menores. Fixe em `~0.1.0`
> se precisar de estabilidade.

> **Já usa o SDK PHP no mesmo repositório?** Cada gerenciador instala só o que é dele: o
> Composer ignora a pasta `js/` (via `export-ignore`) e o npm publica só o `dist/` deste pacote.

## Configuração

Há três modos de autenticação, resolvidos nesta ordem de precedência.

```ts
import { BlobManager } from '@joshualevy029/azure-blob-sdk';

const azure = new BlobManager({
    default: 'apostilas',
    connections: {
        // 1. SAS URL (recomendado): a URL já traz conta, container e token.
        apostilas: { sasUrl: process.env.APOSTILAS_SAS_URL },

        // 2. Connection string.
        contratos: { connectionString: process.env.CONTRATOS_CONNECTION_STRING, container: 'contratos' },

        // 3. Conta + chave.
        backup: { name: 'minhaconta', key: process.env.BACKUP_KEY, container: 'backup', readonly: true },
    },
    http: { timeout: 60 },
    logger: console,
});
```

Só os modos 2 e 3 conseguem **assinar SAS novos**. No modo 1 o SDK reaproveita o token do
container, então a validade e as permissões de `temporaryUrl()` são as do token configurado.

### Opções de uma conexão

| Opção | Padrão | Descrição |
|---|---|---|
| `sasUrl` | — | URL com SAS token no nível do container |
| `connectionString` | — | Connection string do Azure Storage |
| `name` + `key` | — | Nome e chave (base64) da conta |
| `container` | o da SAS URL | Container alvo; obrigatório nos modos 2 e 3 |
| `url` | derivada | Endpoint da conta; preencha para domínio próprio ou Azurite |
| `endpointSuffix` | `core.windows.net` | Sufixo para nuvens soberanas |
| `readonly` | `false` | Trava local: bloqueia escrita antes de a requisição sair |
| `maxDownloadSize` | 5 MB | Teto de `download()` em memória |
| `blockSize` | 4 MB | Tamanho de bloco no upload (entre 1 MB e 256 MB) |
| `apiVersion` | `2022-11-02` | Versão da REST API |
| `http.timeout` | 60 | Segundos até a resposta começar a chegar |
| `http.fetch` | `fetch` | `fetch` alternativo (testes, instrumentação) |
| `http.dispatcher` | — | Dispatcher do undici (proxy, TLS customizado) |
| `logger` | nenhum | Objeto com `warn(message, context)`, como `console` |

As chaves também são aceitas em snake_case (`sas_url`, `max_download_size`…), o formato do
`config/azure-blob.php`, então um mesmo JSON serve às duas linguagens.

### Variáveis de ambiente

`BlobManager.fromEnv()` e `createAzureBlob()` usam os **mesmos nomes do SDK PHP**, então um `.env`
compartilhado atende às duas aplicações:

```dotenv
AZURE_STORAGE_SAS_URL="https://minhaconta.blob.core.windows.net/meu-container?sp=racwdl&..."
# ou AZURE_STORAGE_CONNECTION_STRING=..., ou AZURE_STORAGE_NAME + AZURE_STORAGE_KEY
AZURE_STORAGE_CONTAINER=meu-container
AZURE_READONLY=false
AZURE_MAX_DOWNLOAD_SIZE=5242880
AZURE_BLOCK_SIZE=4194304
AZURE_TIMEOUT=60
```

Também são lidas `AZURE_STORAGE_URL`, `AZURE_STORAGE_ENDPOINT_SUFFIX`, `AZURE_API_VERSION` e
`AZURE_BLOB_CONNECTION` (nome da conexão padrão).

Conexões extras são declaradas em `AZURE_BLOB_CONNECTIONS` e leem as mesmas variáveis, com o nome
da conexão como prefixo:

```dotenv
AZURE_BLOB_CONNECTIONS=apostilas,contratos
APOSTILAS_AZURE_STORAGE_SAS_URL=https://...
APOSTILAS_AZURE_READONLY=true
CONTRATOS_AZURE_STORAGE_CONNECTION_STRING=DefaultEndpointsProtocol=...
CONTRATOS_AZURE_STORAGE_CONTAINER=contratos
```

### Proxy e TLS

O `fetch` nativo não lê `AZURE_PROXY` nem aceita `verify: false` como o Guzzle do SDK PHP. Há dois
caminhos:

```ts
// Node 24+: o fetch nativo respeita HTTP_PROXY/HTTPS_PROXY/NO_PROXY com esta variável.
// NODE_USE_ENV_PROXY=1 node app.js

// Qualquer versão: um dispatcher do undici (npm install undici).
import { ProxyAgent } from 'undici';
new BlobManager({ connections, http: { dispatcher: new ProxyAgent('http://proxy:3128') } });
```

## Uso

Toda chamada aceita caminhos com ou sem barra inicial: `/a//b.txt` e `a/b.txt` apontam para o
mesmo blob. As chamadas diretas no `BlobManager` vão para a conexão padrão.

### Leitura

```ts
const page = await azure.list('2026/', 100);          // uma página (máx. 5.000)
page.names(); page.totalSize(); page.hasMore();

for await (const item of azure.listAll('2026/')) {}   // todas as páginas, sem carregar tudo
const pasta = await azure.directory('2026');          // raso: subpastas em pasta.directories

await azure.files('2026');                            // ['2026/a.pdf', '2026/b.pdf']
await azure.files('2026', true);                      // inclui as subpastas
await azure.directories('2026');                      // ['2026/janeiro', '2026/fevereiro']
await azure.listNames('2026/', 50);                   // até 50 nomes, todas as páginas

const content = await azure.download('a.pdf');        // respeita maxDownloadSize
content.contents;  content.text();  content.json();  await content.saveTo('/tmp/a.pdf');

await azure.downloadJson<Config>('config.json');
await azure.downloadText('notas.txt');
await azure.get('a.bin');                             // Buffer, sem checar tamanho
await azure.stream('grande.zip');                     // Readable
await azure.downloadTo('grande.zip', '/tmp/g.zip');   // stream para o disco; cria a pasta

await azure.exists('a.pdf');   await azure.missing('a.pdf');
const props = await azure.properties('a.pdf');        // size, contentType, metadata, etag…
```

### URLs

```ts
azure.url('a.pdf');                                   // pública (só abre em container público)
azure.temporaryUrl('a.pdf', 2);                       // SAS de leitura por 2 horas
azure.temporaryUrl('a.pdf', new Date('2026-12-31'), 'rw');
azure.temporaryUrl('a.pdf', 1, 'r', { contentDisposition: 'attachment; filename="a.pdf"' });
azure.temporaryContainerUrl(1, 'rl');
```

### Escrita

```ts
await azure.upload('a.txt', 'texto');                 // string, Buffer, Uint8Array, ArrayBuffer
await azure.upload('b.bin', fs.createReadStream('b')); // stream Node, ReadableStream web ou async iterable
await azure.upload('c.pdf', bytes, {
    contentType: 'application/pdf',                   // padrão: detectado pela extensão
    overwrite: false,                                 // 409 se já existir
    metadata: { origem: 'scanner' },
    cacheControl: 'max-age=3600',
    contentDisposition: 'inline',
});

await azure.uploadJson('dados.json', { a: 1 });
await azure.uploadFile('destino/b.pdf', '/tmp/b.pdf');
await azure.setMetadata('a.txt', { revisado: 'sim' });

await azure.delete('a.txt');                          // false se já não existia
await azure.deleteDirectory('rascunhos');             // quantidade removida
await azure.copy('a.pdf', 'b.pdf', { destinationContainer: 'backup', wait: true });
await azure.move('a.pdf', 'arquivo-morto/a.pdf');
```

### Containers e trava de escrita

```ts
await azure.containerExists();
await azure.ensureContainer();                         // cria se faltar (exige chave da conta)

const backup = azure.connection().container('backup'); // nova instância; a original não muda
const leitura = azure.connection().readOnly();         // escrita lança ReadOnlyError
```

### Credencial avulsa

Para credenciais vindas do banco (uma conta por cliente, por exemplo):

```ts
const cliente = azure.build({ sasUrl: registro.sasUrl }, `cliente-${registro.id}`);
```

### Blobs comprimidos (`Content-Encoding`)

Um blob gravado com `contentEncoding: 'gzip'` (ou `br`, `deflate`) é entregue por `get()`,
`download()`, `stream()` e `downloadTo()` **já descomprimido**: o `fetch` nativo do Node decodifica
o corpo conforme o `Content-Encoding` da resposta e não oferece como desligar isso. É o mesmo que um
navegador faria com a URL do blob. Se você precisa dos bytes comprimidos exatamente como foram
enviados, grave o blob **sem** `contentEncoding` (por exemplo, como `application/gzip`).

## Erros

Toda falha é um `AzureBlobError`, com `status`, `errorCode` (o código do Azure) e `context`:

| Classe | Quando |
|---|---|
| `BlobNotFoundError` | 404: blob ou container inexistente |
| `BlobTooLargeError` | `download()` acima de `maxDownloadSize` — use `stream()`/`downloadTo()` |
| `ConfigurationError` | Credencial, container ou conexão faltando |
| `ReadOnlyError` | Escrita numa conexão `readonly` |

```ts
try {
    await azure.get('a.pdf');
} catch (error) {
    if (error instanceof BlobNotFoundError) { /* ... */ }
    if (error instanceof AzureBlobError && error.errorCode === 'AuthorizationPermissionMismatch') { /* ... */ }
}
```

SAS tokens, chaves e assinaturas são mascarados (`[REDACTED]`) no contexto dos erros e nos logs.

## CLI

```bash
npx azure-blob info --check                       # conexões, modo de auth e teste de acesso
npx azure-blob list 2026/ --max=50                # --all, --shallow, --json
npx azure-blob download 2026/a.pdf /tmp/          # ou --stdout > a.pdf
npx azure-blob upload /tmp/a.pdf destino/a.pdf    # --content-type, --no-overwrite
npx azure-blob exists 2026/a.pdf --json           # sai com 1 se não existir
npx azure-blob delete 2026/rascunhos --recursive --force
npx azure-blob copy a.pdf b.pdf --to=backup --wait   # --move, --from
npx azure-blob sas 2026/a.pdf --hours=2 --permissions=r
```

Opções globais: `--connection`, `--container`, `--config <arquivo.json|.mjs>`,
`--env-file <arquivo>` e `--quiet`. Sem `--config`, as credenciais vêm das variáveis de ambiente,
completadas por um `.env` no diretório atual (o ambiente real tem precedência). `delete` pede
confirmação; fora de um terminal interativo, sem `--force`, ele cancela.

## NestJS

```ts
import { AzureBlobModule, InjectBlob } from '@joshualevy029/azure-blob-sdk/nestjs';
import { BlobClient, BlobManager } from '@joshualevy029/azure-blob-sdk';

@Module({
    imports: [AzureBlobModule.forRoot({ isGlobal: true, clients: ['contratos'] })],
})
export class AppModule {}

@Injectable()
export class ApostilasService {
    constructor(
        private readonly azure: BlobManager,
        @InjectBlob() private readonly blob: BlobClient,              // conexão padrão
        @InjectBlob('contratos') private readonly contratos: BlobClient,
    ) {}
}
```

Sem `connections`, o módulo lê as variáveis de ambiente. Para configuração assíncrona:

```ts
AzureBlobModule.forRootAsync({
    imports: [ConfigModule],
    inject: [ConfigService],
    useFactory: (config: ConfigService) => ({
        connections: { default: { sasUrl: config.getOrThrow('AZURE_STORAGE_SAS_URL') } },
    }),
});
```

`BlobClient` também pode ser injetado por tipo, e resolve para a conexão padrão.

## FlyDrive / AdonisJS Drive

```ts
import { Disk } from 'flydrive';
import { AzureBlobDriver } from '@joshualevy029/azure-blob-sdk/flydrive';

const disk = new Disk(new AzureBlobDriver({ sasUrl: process.env.AZURE_STORAGE_SAS_URL }));

await disk.put('avatars/1.png', bytes, { contentType: 'image/png' });
await disk.getSignedUrl('avatars/1.png', { expiresIn: '30mins' });
```

No AdonisJS, registre o driver em `config/drive.ts`:

```ts
services: {
    azure: () => new AzureBlobDriver({ sasUrl: env.get('AZURE_STORAGE_SAS_URL') }, { prefix: 'uploads' }),
},
```

O driver aceita um `BlobClient` já montado ou as opções de uma conexão, e um `prefix` opcional.
Visibilidade **não é suportada de propósito**: no Azure, o acesso público é definido no container,
não por blob. `getVisibility`, `setVisibility` e `put(..., { visibility })` lançam erro em vez de
fingir que tiveram efeito. `getSignedUploadUrl()` gera um SAS com `cw`; o `PUT` do navegador precisa
do cabeçalho `x-ms-blob-type: BlockBlob`.

## Testes na sua aplicação

Injete um `fetch` falso na configuração. Nenhuma requisição sai para a rede:

```ts
const azure = new BlobManager({
    connections: { default: { sasUrl: 'https://conta.blob.core.windows.net/c?sig=x' } },
    http: { fetch: async (url, init) => new Response('conteúdo', { status: 200 }) },
});
```

## Diferenças em relação ao SDK PHP

| | PHP / Laravel | Node |
|---|---|---|
| Conexão padrão | facade `AzureBlob::list()` | `azure.list()` no `BlobManager` |
| Integração de storage | `Storage::disk('azure')` (Flysystem) | driver FlyDrive |
| Comandos | `php artisan azure:list` | `npx azure-blob list` |
| Opções de upload | `content_type`, `cache_control` | `contentType`, `cacheControl` |
| Proxy / TLS | `AZURE_PROXY`, `AZURE_VERIFY_SSL` | `http.dispatcher` ou `NODE_USE_ENV_PROXY` |
| Log | canal `azure-blob` do Laravel | `logger` opcional (silencioso por padrão) |
| Timeout | total da requisição | até a resposta começar (streams não são cortados) |
| Nomes com `.`/`..` | aceitos | recusados (o parser de URL do `fetch` resolveria `a/../b` para `b`) |
| `deleteDirectory('')` | esvazia o container | recusado: exige um prefixo |

O `toJSON()` dos resultados produz o mesmo formato do `toArray()` do PHP (snake_case, datas ATOM),
então a saída JSON das duas linguagens — inclusive a dos CLIs — é intercambiável.

## Desenvolvimento

```bash
cd js
npm install
npm run typecheck
npm test                  # unitários e de fluxo, contra um Azure falso em memória
npm run test:coverage

# e2e contra o Azurite:
docker run -d -p 10000:10000 mcr.microsoft.com/azure-storage/azurite azurite-blob --blobHost 0.0.0.0
AZURITE_URL=http://127.0.0.1:10000 npm run test:e2e
```

## Licença

MIT.
