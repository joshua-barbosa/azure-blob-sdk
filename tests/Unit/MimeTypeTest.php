<?php

namespace AzureBlob\Tests\Unit;

use AzureBlob\Support\MimeType;
use PHPUnit\Framework\TestCase;

class MimeTypeTest extends TestCase
{
    public function test_detecta_tipos_comuns_pela_extensao(): void
    {
        $this->assertSame('application/pdf', MimeType::guess('pasta/relatorio.pdf'));
        $this->assertSame('application/json', MimeType::guess('dados.json'));
        $this->assertSame('image/png', MimeType::guess('imagem.PNG'));
        $this->assertSame('text/plain', MimeType::guess('a/b/c.txt'));
    }

    public function test_sem_extensao_cai_no_tipo_generico(): void
    {
        $this->assertSame(MimeType::DEFAULT, MimeType::guess('arquivo-sem-extensao'));
        $this->assertSame(MimeType::DEFAULT, MimeType::guess(''));
    }

    public function test_extensao_desconhecida_cai_no_tipo_generico(): void
    {
        $this->assertSame(MimeType::DEFAULT, MimeType::guess('a.qualquercoisa'));
    }

    public function test_from_contents_prefere_a_extensao_quando_ela_e_conclusiva(): void
    {
        $this->assertSame('application/pdf', MimeType::fromContents('conteudo qualquer', 'a.pdf'));
    }

    public function test_from_contents_inspeciona_o_conteudo_sem_extensao(): void
    {
        $detected = MimeType::fromContents("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n", 'sem-extensao');

        $this->assertSame('application/pdf', $detected);
    }

    public function test_from_contents_com_conteudo_vazio_devolve_o_generico(): void
    {
        $this->assertSame(MimeType::DEFAULT, MimeType::fromContents('', 'x'));
    }
}
