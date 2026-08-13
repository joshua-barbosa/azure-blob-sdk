# Comandos Artisan

Todos aceitam `--connection=` (conexão de `config/azure-blob.php`) e
`--container=` (sobrescreve o container da conexão).

| Comando | Função |
|---|---|
| `azure:info` | Conexões configuradas, modo de autenticação e validação de acesso |
| `azure:list` | Lista blobs |
| `azure:download` | Baixa um blob |
| `azure:upload` | Envia um arquivo |
| `azure:exists` | Verifica existência e mostra propriedades |
| `azure:delete` | Remove blob ou prefixo |
| `azure:copy` | Copia ou move |
| `azure:sas` | Gera URL assinada |

## azure:info

```bash
php artisan azure:info
php artisan azure:info --check          # lista 1 blob de cada conexão para provar o acesso
php artisan azure:info --connection=apostilas
```

Sai com código 1 se alguma conexão falhar. É o primeiro comando a rodar depois
de configurar o pacote.

## azure:list

```bash
php artisan azure:list 2026/ --max=50
php artisan azure:list --shallow        # agrupa subpastas em vez da árvore inteira
php artisan azure:list --all --json     # todas as páginas, saída consumível por outro processo
```

## azure:download

```bash
php artisan azure:download 2026/a.pdf /tmp/       # diretório: usa o nome do blob
php artisan azure:download 2026/a.pdf /tmp/x.pdf
php artisan azure:download 2026/a.pdf --stdout > a.pdf
```

Grava por stream: um arquivo de 2 GB não passa pela memória.

## azure:upload

```bash
php artisan azure:upload /tmp/a.pdf destino/a.pdf
php artisan azure:upload /tmp/a.pdf                    # usa o basename
php artisan azure:upload /tmp/a.pdf --no-overwrite --content-type=application/pdf
```

## azure:exists

```bash
php artisan azure:exists 2026/a.pdf
php artisan azure:exists 2026/a.pdf --json
```

Sai com código 1 quando o blob não existe, o que torna o comando usável direto
em script de shell:

```bash
php artisan azure:exists 2026/a.pdf --quiet && echo "existe"
```

## azure:delete

```bash
php artisan azure:delete 2026/a.pdf                    # pede confirmação
php artisan azure:delete 2026/a.pdf --force
php artisan azure:delete 2026/rascunhos --recursive --force
```

A remoção é irreversível sem soft delete habilitado na conta, então a
confirmação é o padrão e `--force` é a exceção.

## azure:copy

```bash
php artisan azure:copy a.pdf b.pdf --to=processados --wait
php artisan azure:copy a.pdf arquivo-morto/a.pdf --move
```

`--wait` aguarda cópias assíncronas; `--move` apaga a origem depois.

## azure:sas

```bash
php artisan azure:sas 2026/a.pdf --hours=2 --permissions=r
php artisan azure:sas --permissions=rl                 # container inteiro
```

Numa conexão em modo SAS URL o comando avisa que o token do container foi
reaproveitado e que `--hours`/`--permissions` não se aplicam.

## Ao adicionar um comando

**Nenhuma descrição de argumento pode conter `--`.** O parser de assinatura do
Laravel 8 casa `/-{2,}(.*)/` **sem âncora**, então um `--algo` no meio do texto
faz o argumento inteiro ser lido como opção e o comando quebra em runtime com
`The "x" argument does not exist`. Só o Laravel 9+ ancorou a expressão.
`tests/Unit/CommandSignatureTest.php` guarda essa regra.

O método sobrescrito é `perform()`, não `execute()` nem `runCommand()` — os dois
já existem em `Illuminate\Console\Command` (herdados do Symfony), e redeclarar um
método concreto da base como abstrato é erro fatal de compilação.
