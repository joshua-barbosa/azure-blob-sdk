<?php

namespace AzureBlob\Tests\Unit;

use AzureBlob\Support\Path;
use PHPUnit\Framework\TestCase;

class PathTest extends TestCase
{
    public function test_normalize_remove_barra_inicial_e_colapsa_duplicadas(): void
    {
        $this->assertSame('pasta/arquivo.txt', Path::normalize('/pasta/arquivo.txt'));
        $this->assertSame('pasta/arquivo.txt', Path::normalize('pasta//arquivo.txt'));
        $this->assertSame('pasta/arquivo.txt', Path::normalize('///pasta///arquivo.txt'));
        $this->assertSame('', Path::normalize('/'));
    }

    public function test_normalize_converte_barra_invertida(): void
    {
        $this->assertSame('pasta/arquivo.txt', Path::normalize('pasta\\arquivo.txt'));
    }

    public function test_encode_preserva_as_barras_entre_segmentos(): void
    {
        // rawurlencode sozinho viraria a/b em a%2Fb e quebraria a assinatura.
        $this->assertSame('pasta/sub/a.txt', Path::encode('pasta/sub/a.txt'));
    }

    public function test_encode_codifica_caracteres_especiais_de_cada_segmento(): void
    {
        $this->assertSame('minha%20pasta/rela%C3%A7%C3%A3o%2Bfinal.pdf', Path::encode('minha pasta/relação+final.pdf'));
        $this->assertSame('a%26b/c%3Fd.txt', Path::encode('a&b/c?d.txt'));
    }

    public function test_directory_prefix_sempre_termina_em_barra(): void
    {
        $this->assertSame('pasta/', Path::directoryPrefix('pasta'));
        $this->assertSame('pasta/', Path::directoryPrefix('/pasta/'));
        $this->assertSame('a/b/', Path::directoryPrefix('a/b'));
        $this->assertSame('', Path::directoryPrefix(''));
        $this->assertSame('', Path::directoryPrefix('/'));
    }

    public function test_basename_e_dirname(): void
    {
        $this->assertSame('a.txt', Path::basename('pasta/sub/a.txt'));
        $this->assertSame('a.txt', Path::basename('a.txt'));
        $this->assertSame('pasta/sub', Path::dirname('pasta/sub/a.txt'));
        $this->assertSame('', Path::dirname('a.txt'));
    }
}
