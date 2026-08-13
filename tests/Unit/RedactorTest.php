<?php

namespace AzureBlob\Tests\Unit;

use AzureBlob\Support\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public function test_mascara_chaves_sensiveis(): void
    {
        $clean = Redactor::scrub([
            'key' => 'chave-secreta',
            'sas_token' => 'sp=r&sig=abc',
            'connection_string' => 'AccountKey=xyz',
            'Authorization' => 'SharedKey conta:assinatura',
            'container' => 'publico',
        ]);

        $this->assertSame(Redactor::MASK, $clean['key']);
        $this->assertSame(Redactor::MASK, $clean['sas_token']);
        $this->assertSame(Redactor::MASK, $clean['connection_string']);
        $this->assertSame(Redactor::MASK, $clean['Authorization']);
        $this->assertSame('publico', $clean['container']);
    }

    public function test_mascara_a_assinatura_dentro_de_urls(): void
    {
        // Um SAS no log é credencial vazada: quem lê o arquivo ganha o acesso.
        $clean = Redactor::scrub([
            'url' => 'https://c.blob.core.windows.net/x/a.pdf?sp=r&se=2026-01-01T00:00:00Z&sig=SEGREDO123',
        ]);

        $this->assertStringNotContainsString('SEGREDO123', $clean['url']);
        $this->assertStringContainsString('sp=r', $clean['url']);
        $this->assertStringContainsString('sig='.Redactor::MASK, $clean['url']);
    }

    public function test_percorre_arrays_aninhados(): void
    {
        $clean = Redactor::scrub(['conexao' => ['nome' => 'x', 'key' => 'secreta']]);

        $this->assertSame('x', $clean['conexao']['nome']);
        $this->assertSame(Redactor::MASK, $clean['conexao']['key']);
    }

    public function test_normaliza_separadores_no_nome_da_chave(): void
    {
        $clean = Redactor::scrub(['account-key' => 'x', 'shared access signature' => 'y']);

        $this->assertSame(Redactor::MASK, $clean['account-key']);
        $this->assertSame(Redactor::MASK, $clean['shared access signature']);
    }

    public function test_preserva_valores_nao_textuais(): void
    {
        $clean = Redactor::scrub(['status' => 404, 'ok' => false, 'nada' => null]);

        $this->assertSame(404, $clean['status']);
        $this->assertFalse($clean['ok']);
        $this->assertNull($clean['nada']);
    }

    public function test_url_sem_assinatura_passa_intacta(): void
    {
        $url = 'https://c.blob.core.windows.net/x/a.pdf';

        $this->assertSame($url, Redactor::scrubUrl($url));
    }
}
