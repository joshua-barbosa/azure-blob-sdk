<?php

namespace AzureBlob\Tests\Unit;

use AzureBlob\Console;
use Illuminate\Console\Parser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regressão do parser de assinatura dos comandos.
 *
 * O Laravel 8 casa `/-{2,}(.*)/` SEM âncora ao decidir se um token da assinatura
 * é argumento ou opção. Um "--algo" no meio da descrição de um ARGUMENTO faz o
 * argumento inteiro virar opção, e o comando morre em runtime com
 * 'The "x" argument does not exist'. Só o Laravel 9+ ancorou a expressão, então
 * a regra vale enquanto o pacote suportar o 8.
 *
 * Os laços são internos de propósito: `@dataProvider` em docblock saiu no
 * PHPUnit 12 e atributos não existem no 9 — o pacote precisa rodar nos dois.
 */
class CommandSignatureTest extends TestCase
{
    private const COMMANDS = [
        Console\CopyCommand::class,
        Console\DeleteCommand::class,
        Console\DownloadCommand::class,
        Console\ExistsCommand::class,
        Console\InfoCommand::class,
        Console\ListCommand::class,
        Console\SasCommand::class,
        Console\UploadCommand::class,
    ];

    public function test_nenhuma_descricao_de_argumento_contem_hifen_duplo(): void
    {
        foreach (self::COMMANDS as $class) {
            preg_match_all('/\{\s*(.*?)\s*\}/', $this->signature($class), $tokens);

            $this->assertNotEmpty($tokens[1], $class.': assinatura sem parâmetros.');

            foreach ($tokens[1] as $token) {
                if (str_starts_with($token, '--')) {
                    continue;
                }

                $this->assertStringNotContainsString(
                    '--',
                    $token,
                    sprintf(
                        '%s: a descrição do argumento "%s" contém "--", o que quebra o parser do Laravel 8.',
                        $class,
                        explode(' : ', $token)[0]
                    )
                );
            }
        }
    }

    public function test_toda_assinatura_produz_nome_argumentos_e_opcoes_validos(): void
    {
        foreach (self::COMMANDS as $class) {
            [$name, $arguments, $options] = Parser::parse($this->signature($class));

            $this->assertStringStartsWith('azure:', $name, $class.': nome de comando inesperado.');

            $optionNames = array_map(static fn ($option): string => $option->getName(), $options);

            // As opções comuns precisam sobreviver ao parse em toda a matriz.
            $this->assertContains('connection', $optionNames, $class.': --connection sumiu.');

            foreach ($arguments as $argument) {
                // Um argumento mal parseado vira um "nome" com a descrição junto.
                $this->assertStringNotContainsString(' ', $argument->getName(), $class.': argumento malformado.');
            }
        }
    }

    public function test_os_argumentos_esperados_de_cada_comando_existem(): void
    {
        $esperado = [
            Console\CopyCommand::class => ['source', 'destination'],
            Console\DeleteCommand::class => ['blob'],
            Console\DownloadCommand::class => ['blob', 'destination'],
            Console\ExistsCommand::class => ['blob'],
            Console\InfoCommand::class => [],
            Console\ListCommand::class => ['prefix'],
            Console\SasCommand::class => ['blob'],
            Console\UploadCommand::class => ['file', 'blob'],
        ];

        foreach ($esperado as $class => $nomes) {
            [, $arguments] = Parser::parse($this->signature($class));

            $this->assertSame(
                $nomes,
                array_map(static fn ($argument): string => $argument->getName(), $arguments),
                $class.': os argumentos parseados não batem com os declarados.'
            );
        }
    }

    private function signature(string $class): string
    {
        return (string) (new ReflectionClass($class))->getDefaultProperties()['signature'];
    }
}
