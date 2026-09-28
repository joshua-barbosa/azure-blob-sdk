# Changelog

Todas as mudanças relevantes deste pacote são registradas aqui.

O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/),
e o versionamento segue o [SemVer](https://semver.org/lang/pt-BR/). Enquanto o
pacote estiver em `0.x`, mudanças incompatíveis podem acontecer entre versões
menores — fixe em `~0.1.0` se precisar de estabilidade.

## [Não lançado]

### Adicionado

- `files()`, `directories()` e `listNames()` para ler "pastas" e nomes
  percorrendo todas as páginas.
- `downloadText()`, `containerExists()` e `ensureContainer()`, que vêm do
  `azure.py` do vetorizador. Equivalências em
  [docs/python-migration.md](docs/python-migration.md).
- Testes e2e contra o Azurite (`--testsuite E2E`, com `AZURITE_URL`) e um job no
  CI para eles.

### Corrigido

- `cache_control`, `content_disposition`, `content_encoding` e
  `content_language` eram descartados no upload simples. Eles iam como
  cabeçalhos padrão, que o Azure ignora no `Put Blob`; agora vão como
  `x-ms-blob-*`.
- Uma cópia que terminava `failed`/`aborted` era tratada como concluída, e
  `move()` apagava a origem mesmo sem o destino existir. Agora ela lança
  exceção e a origem é preservada.
- `move()` com origem igual ao destino apagava o blob. Agora é recusado.
- `downloadTo()` truncava o arquivo de destino antes do download. Um 404 ou
  uma queda no meio deixavam um arquivo vazio ou parcial. Agora ele grava num
  temporário, renomeia no fim e cria a pasta de destino quando falta.
- `deleteDirectory()` renormalizava os nomes vindos da listagem, e blobs como
  `dir//a` ou `dir/a ` não eram apagados. Agora os nomes são usados exatamente
  como o Azure os devolve.
- `uploadFile()` tirava o Content-Type do arquivo local, e um temporário sem
  extensão virava `application/octet-stream`. Agora o nome do blob tem
  precedência.

### Alterado

- `deleteDirectory('')` recusa o prefixo vazio em vez de esvaziar o container
  inteiro.

## [0.1.0] — 2026-08-13

Primeira versão. Substitui o servidor MCP em Python que expunha o mesmo storage,
com paridade completa das 11 operações.

### Adicionado

- Cliente do Azure Blob Storage sobre `Illuminate\Http`, com assinatura
  **Shared Key** (HMAC-SHA256) e geração de **Service SAS** próprias. Sem
  dependência do `microsoft/azure-storage-blob`, abandonado desde 2021 e sem
  suporte a PHP 8.2+.
- Três modos de autenticação: SAS URL, connection string e conta + chave, com
  precedência nessa ordem.
- Múltiplas conexões nomeadas, no lugar do `AZURE_PREFIX` da ferramenta MCP.
  Conexões avulsas via `BlobManager::build()`, para credenciais vindas do banco.
- Facade `AzureBlob` e injeção de `BlobClient` / `BlobManager`.
- Driver `azure-blob` para `Storage::disk()`, com adaptadores para Flysystem 3
  (Laravel 9+) e Flysystem 1 (Laravel 8).
- Oito comandos Artisan: `azure:info`, `azure:list`, `azure:download`,
  `azure:upload`, `azure:exists`, `azure:delete`, `azure:copy` e `azure:sas`.
- Upload fatiado em blocos acima de `block_size`, contornando o teto de 256 MiB
  do PUT simples e mantendo o uso de memória constante.
- `stream()` e `downloadTo()` para leitura de arquivos grandes sem passar pela
  memória; `listAll()` percorre todas as páginas via Generator.
- Trava local de somente leitura por conexão, que falha antes de a requisição
  sair.
- Exceções tipadas com `status()`, `errorCode()` e `context()`.
- Mascaramento de chaves, SAS tokens e assinaturas antes de qualquer escrita em
  log.

### Compatibilidade

Laravel 8 a 13, PHP 8.0 a 8.4. Validado em PHP 8.0/Laravel 8/Flysystem 1,
PHP 8.2/Laravel 10 e PHP 8.3/Laravel 13.

[Não lançado]: https://github.com/joshua-barbosa/azure-blob-sdk/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/joshua-barbosa/azure-blob-sdk/releases/tag/v0.1.0
