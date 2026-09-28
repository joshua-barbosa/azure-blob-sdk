# Contribuindo

Obrigado por considerar contribuir. Issues e pull requests são bem-vindos.

## Antes de abrir um PR

```bash
composer install
composer test    # phpunit
composer lint    # pint --test
```

Os dois precisam passar. `composer fix` aplica a formatação automaticamente.

> **Desenvolver o pacote exige PHP 8.1+**, embora ele *rode* em 8.0 — o
> `laravel/pint` exige 8.1. Para instalar as dependências de desenvolvimento em
> PHP 8.0: `composer remove --dev laravel/pint --no-update`.

### Pacote Node (`js/`)

```bash
cd js
npm install
npm run typecheck
npm test
```

Para os testes contra o Azurite, veja a seção "Desenvolvimento" de
[js/README.md](js/README.md). As assinaturas Shared Key e SAS são conferidas contra
vetores gerados pelo SDK PHP (`js/test/unit/signing.test.ts`): uma mudança de
assinatura num lado precisa ser refletida no outro.

## O que o CI cobra

A matriz roda Laravel 8 a 13 em PHP 8.0 a 8.4. Vale rodar localmente pelo menos
a linha do **Laravel 8** antes de abrir o PR, porque é onde as diferenças
aparecem:

```bash
composer config policy.advisories.block false
composer remove --dev laravel/pint --no-update
composer require --no-update --dev "orchestra/testbench:6.*"
php8.0 $(which composer) update -W \
  --with "illuminate/console:8.*" --with "illuminate/contracts:8.*" \
  --with "illuminate/filesystem:8.*" --with "illuminate/http:8.*" \
  --with "illuminate/support:8.*"
php8.0 vendor/bin/phpunit
```

## Armadilhas conhecidas

Cada uma destas já quebrou o pacote uma vez e tem teste de regressão:

- **Nenhuma descrição de argumento de comando pode conter `--`.** O parser de
  assinatura do Laravel 8 casa `/-{2,}(.*)/` sem âncora, então um `--algo` no
  meio do texto faz o argumento virar opção e o comando morre em runtime.
  Guardado por `tests/Unit/CommandSignatureTest.php`.
- **O método sobrescrito nos comandos é `perform()`**, não `execute()` nem
  `runCommand()` — os dois já existem em `Illuminate\Console\Command`, e
  redeclarar um método concreto da base como abstrato é erro fatal.
- **Não use `@dataProvider` em docblock nem atributos do PHPUnit.** O pacote
  roda em PHPUnit 9 a 12: o primeiro saiu no 12, os segundos não existem no 9.
  Faça o laço dentro do próprio teste.
- **`expectsOutputToContain()` só existe do Laravel 9 em diante.** Use o helper
  `runCommand()` de `tests/TestCase.php`, que captura a saída inteira.
- **O string-to-sign do Shared Key é posicional.** Qualquer linha fora de ordem
  produz `403 AuthenticationFailed` e nada mais específico. Se mexer em
  `SharedKeySigner`, os testes fixam o formato inteiro de propósito.
- **A ordem das permissões do SAS importa**: `rw` é aceito, `wr` não.

## Testes

A suíte usa `Http::fake()` — nenhuma requisição sai para o Azure. Nunca
comprometa uma credencial real num teste ou numa issue; as dos testes são
propositalmente falsas.

Cobertura mínima esperada: 80% de linhas.

```bash
vendor/bin/phpunit --coverage-text --coverage-filter src
```

## Estilo

- `laravel/pint` com o preset `laravel` (ver `pint.json`).
- Comentários e mensagens de exceção em português, como o resto do pacote.
- Comentário explica **por quê**, não o quê. Se o código precisa de comentário
  para dizer o que faz, geralmente ele é que precisa mudar.

## Segurança

Vulnerabilidades não vão em issue pública — ver [SECURITY.md](SECURITY.md).

## Apoie

Se o pacote te ajudou, [me paga um café](https://www.buymeacoffee.com/joshuabarbosa) ☕
