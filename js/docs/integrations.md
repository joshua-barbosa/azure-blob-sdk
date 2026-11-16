# Integrações

As duas são *peer dependencies* opcionais, importadas por subcaminho:
`@joshualevy029/azure-blob-sdk/nestjs` e `@joshualevy029/azure-blob-sdk/flydrive`.

## NestJS

Requer `@nestjs/common` 10, 11 ou 12.

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

### Opções de `forRoot()`

As mesmas do `BlobManager` (`default`, `connections`, `http`, `logger`), mais:

| Opção | Função |
|---|---|
| `isGlobal` | Registra o módulo como global, dispensando importá-lo em cada módulo |
| `clients` | Conexões nomeadas que ganham provider próprio para `@InjectBlob(nome)` |

Sem `connections`, o módulo lê as variáveis de ambiente (`BlobManager.fromEnv()`),
aplicando por cima o `http` e o `logger` informados.

### Configuração assíncrona

```ts
AzureBlobModule.forRootAsync({
    imports: [ConfigModule],
    inject: [ConfigService],
    isGlobal: true,
    clients: ['contratos'],
    useFactory: (config: ConfigService) => ({
        connections: { default: { sasUrl: config.getOrThrow('AZURE_STORAGE_SAS_URL') } },
    }),
});
```

### O que pode ser injetado

| Injeção | Resolve para |
|---|---|
| `BlobManager` (por tipo) ou `@InjectBlobManager()` | O gerenciador |
| `BlobClient` (por tipo) ou `@InjectBlob()` | Cliente da conexão padrão |
| `@InjectBlob('nome')` | Cliente da conexão `nome`, se listada em `clients` |
| `getBlobToken(nome?)` | O token do provider, para `useFactory`/testes |
| `AZURE_BLOB_OPTIONS` | As opções resolvidas do gerenciador |

Uma conexão fora de `clients` continua acessível por
`manager.connection('nome')`.

## FlyDrive / AdonisJS Drive

Requer `flydrive` 2.x, que por sua vez exige Node 24+.

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

O primeiro argumento é um `BlobClient` já montado ou as opções de uma conexão.
O segundo aceita `prefix`, aplicado a todas as chaves como um subdiretório raiz
do disco. `driver.bucket('outro')` devolve um driver apontando para outro
container, com o mesmo prefixo, e `driver.client` expõe o `BlobClient`.

### Mapeamento

| FlyDrive | SDK | Observação |
|---|---|---|
| `exists` | `exists()` | |
| `get` / `getBytes` / `getStream` | `get()` / `stream()` | `get` decodifica como UTF-8 |
| `getMetaData` | `properties()` | `contentLength`, `etag`, `lastModified`, `contentType` |
| `getUrl` | `url()` | Só abre em container público |
| `getSignedUrl` | `temporaryUrl(..., 'r')` | Aceita `contentType` e `contentDisposition` |
| `getSignedUploadUrl` | `temporaryUrl(..., 'cw')` | O `PUT` do navegador precisa de `x-ms-blob-type: BlockBlob` |
| `put` / `putStream` | `upload()` | `contentType`, `contentLanguage`, `contentEncoding`, `contentDisposition`, `cacheControl`, `metadata` |
| `copy` | `copy(..., { wait: true })` | |
| `move` | `move()` | |
| `delete` | `delete()` | Apagar o que não existe não é erro |
| `deleteAll` | `deleteDirectory()` | Prefixo vazio é recusado |
| `listAll` | `list()` | Páginas de 1000; `recursive: false` agrupa subpastas |

`expiresIn` aceita segundos (número) ou expressões como `30mins`, `2 hours`,
`1d`. O padrão é 30 minutos. Numa conexão em modo SAS URL o token do container é
reaproveitado e `expiresIn` não se aplica. Em conta `https://` a URL assinada
sai com `spr=https`.

### Visibilidade

**Não é suportada de propósito.** No Azure o acesso público é definido no
container, não por blob. `getVisibility()`, `setVisibility()` e
`put(..., { visibility })` lançam `AzureBlobError` em vez de fingir que tiveram
efeito — o mesmo critério do adaptador Flysystem do SDK PHP.
