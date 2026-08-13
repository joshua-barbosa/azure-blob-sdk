# Changelog

Todas as mudanças relevantes deste pacote são registradas aqui.

O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/),
e o versionamento segue o [SemVer](https://semver.org/lang/pt-BR/). Enquanto o
pacote estiver em `0.x`, mudanças incompatíveis podem acontecer entre versões
menores — fixe em `~0.1.0` se precisar de estabilidade.

## [Não lançado]

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
