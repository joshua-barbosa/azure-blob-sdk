# Changelog — @joshualevy029/azure-blob-sdk

Todas as mudanças relevantes do pacote Node são registradas aqui. O SDK PHP tem o
próprio [CHANGELOG](../CHANGELOG.md).

O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/), e o
versionamento segue o [SemVer](https://semver.org/lang/pt-BR/). Enquanto o pacote
estiver em `0.x`, mudanças incompatíveis podem acontecer entre versões menores.

## [Não lançado]

### Adicionado

- Toda requisição leva um `x-ms-client-request-id` (UUID). Em caso de erro, ele e o
  `x-ms-request-id` da resposta ficam no contexto (`requestId`, `clientRequestId`) e no log.

- Documentação de referência em `js/docs/`: API, autenticação, CLI e integrações (NestJS e
  FlyDrive).

### Alterado

- O SAS gerado por `temporaryUrl()`, `temporaryContainerUrl()` e pela cópia agora sai com
  `spr=https` quando o endpoint da conta é https: o link deixa de valer em http. Em endpoint
  http (Azurite) nada muda.

## [0.1.0] — 2026-09-28

Primeira versão. Porte do SDK PHP para Node.js/TypeScript, com a mesma API.

### Adicionado

- Cliente do Azure Blob Storage sobre o `fetch` nativo, com assinatura **Shared Key**
  e geração de **Service SAS** pelo `node:crypto`. Nenhuma dependência de runtime.
  As assinaturas são idênticas, byte a byte, às do SDK PHP.
- Três modos de autenticação: SAS URL, connection string e conta + chave.
- `BlobManager` com múltiplas conexões, configuração por objeto ou pelas mesmas
  variáveis de ambiente do SDK PHP, e conexões extras via `AZURE_BLOB_CONNECTIONS`.
- Upload em blocos acima de `blockSize` para strings, buffers e streams (Node, web e
  iteráveis assíncronos), com uso de memória constante.
- `stream()` e `downloadTo()` para arquivos grandes; paginação automática com `listAll()`.
- Leitura de "pastas" e nomes com `files()`, `directories()` e `listNames()`, além de
  `downloadText()`, `containerExists()` e `ensureContainer()`, que vêm do `azure.py`
  do vetorizador.
- Erros tipados (`BlobNotFoundError`, `BlobTooLargeError`, `ConfigurationError`,
  `ReadOnlyError`) com `status`, `errorCode` e contexto sem credenciais.
- CLI `azure-blob` com `info`, `list`, `download`, `upload`, `exists`, `delete`, `copy`
  e `sas`, equivalentes aos comandos `azure:*` do Artisan.
- Módulo NestJS (`/nestjs`) e driver FlyDrive / AdonisJS Drive (`/flydrive`).
- Saída em ESM e CommonJS, com tipos.

[Não lançado]: https://github.com/joshua-barbosa/azure-blob-sdk/compare/js-v0.1.0...HEAD
[0.1.0]: https://github.com/joshua-barbosa/azure-blob-sdk/releases/tag/js-v0.1.0
