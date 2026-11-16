# CLI `azure-blob`

Equivalente aos comandos `azure:*` do Artisan no SDK PHP, com a mesma saída
JSON.

```bash
npx azure-blob <comando> [argumentos] [opções]
```

| Comando | Função |
|---|---|
| `info` | Conexões configuradas, modo de autenticação e validação de acesso |
| `list` | Lista blobs |
| `download` | Baixa um blob |
| `upload` | Envia um arquivo |
| `exists` | Verifica existência e mostra propriedades |
| `delete` | Remove blob ou prefixo |
| `copy` | Copia ou move |
| `sas` | Gera URL assinada |

## Opções globais

| Opção | Função |
|---|---|
| `-c`, `--connection <nome>` | Conexão (padrão: `AZURE_BLOB_CONNECTION` ou `default`) |
| `--container <nome>` | Sobrescreve o container da conexão |
| `--config <arquivo>` | JSON ou módulo JS com as opções do `BlobManager` |
| `--env-file <arquivo>` | Arquivo `.env` (padrão: `./.env`, se existir) |
| `-q`, `--quiet` | Suprime mensagens; dados (JSON, `--stdout`, URLs) continuam saindo |
| `-h`, `--help` | Ajuda geral ou do comando |
| `-v`, `--version` | Versão do pacote |

**De onde vem a configuração.** Com `--config`, só o arquivo vale: um `.json`
é lido direto, e qualquer outra extensão é importada como módulo (export
`default` ou o próprio módulo). Sem `--config`, valem as variáveis de ambiente
— as mesmas do SDK PHP —, completadas pelo `.env`. O ambiente real tem
precedência sobre o arquivo. Um `--env-file` que não existe é erro.

**Código de saída.** `0` em sucesso, `1` em qualquer falha. Em erro vindo do
Azure, a mensagem é seguida de `Código do Azure: <código>` em stderr.

## info

```bash
npx azure-blob info
npx azure-blob info --check                 # lista 1 blob de cada conexão para provar o acesso
npx azure-blob info --connection=apostilas
```

Sai com código 1 se alguma conexão falhar. É o primeiro comando a rodar depois
de configurar o pacote.

## list

```bash
npx azure-blob list 2026/ --max=50
npx azure-blob list --shallow               # agrupa subpastas em vez da árvore inteira
npx azure-blob list --all --json            # todas as páginas, saída consumível por outro processo
```

## download

```bash
npx azure-blob download 2026/a.pdf /tmp/        # diretório: usa o nome do blob
npx azure-blob download 2026/a.pdf /tmp/x.pdf
npx azure-blob download 2026/a.pdf --stdout > a.pdf
```

Grava por stream: um arquivo de 2 GB não passa pela memória. `--stdout` é a
exceção — carrega o blob inteiro antes de escrever.

## upload

```bash
npx azure-blob upload /tmp/a.pdf destino/a.pdf
npx azure-blob upload /tmp/a.pdf                # usa o nome do arquivo
npx azure-blob upload /tmp/a.pdf --no-overwrite --content-type=application/pdf
```

## exists

```bash
npx azure-blob exists 2026/a.pdf
npx azure-blob exists 2026/a.pdf --json
```

Sai com código 1 quando o blob não existe, o que torna o comando usável direto
em script de shell:

```bash
npx azure-blob exists 2026/a.pdf --quiet && echo "existe"
```

## delete

```bash
npx azure-blob delete 2026/a.pdf                # pede confirmação
npx azure-blob delete 2026/a.pdf --force
npx azure-blob delete 2026/rascunhos --recursive --force
```

A remoção é irreversível sem soft delete habilitado na conta, então a
confirmação é o padrão e `--force` (`-f`) é a exceção. Fora de um terminal
interativo, sem `--force`, o comando cancela.

## copy

```bash
npx azure-blob copy a.pdf b.pdf --to=processados --wait
npx azure-blob copy a.pdf b.pdf --from=entrada --to=processados
npx azure-blob copy a.pdf arquivo-morto/a.pdf --move
```

`--wait` aguarda cópias assíncronas; `--move` apaga a origem depois.

## sas

```bash
npx azure-blob sas 2026/a.pdf --hours=2 --permissions=r
npx azure-blob sas --permissions=rl             # container inteiro
```

Numa conexão em modo SAS URL o comando avisa (em stderr) que o token do
container foi reaproveitado e que `--hours`/`--permissions` não se aplicam.
Em conta `https://` o link sai com `spr=https`.
