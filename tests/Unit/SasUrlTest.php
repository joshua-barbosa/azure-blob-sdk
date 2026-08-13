<?php

namespace AzureBlob\Tests\Unit;

use AzureBlob\Support\SasUrl;
use PHPUnit\Framework\TestCase;

class SasUrlTest extends TestCase
{
    public function test_decompoe_uma_sas_url_de_container(): void
    {
        $parsed = SasUrl::parse('https://contateste.blob.core.windows.net/apostilas?sp=racwdl&sr=c&sig=abc%3D');

        $this->assertSame('https://contateste.blob.core.windows.net', $parsed['account_url']);
        $this->assertSame('contateste', $parsed['account_name']);
        $this->assertSame('apostilas', $parsed['container']);
        $this->assertSame('sp=racwdl&sr=c&sig=abc%3D', $parsed['sas_token']);
    }

    public function test_preserva_o_encoding_original_do_token(): void
    {
        // Reencodar "sig" invalidaria a assinatura, então ela sai crua.
        $parsed = SasUrl::parse('https://c.blob.core.windows.net/x?sig=a%2Bb%2Fc%3D');

        $this->assertSame('sig=a%2Bb%2Fc%3D', $parsed['sas_token']);
    }

    public function test_aceita_url_sem_container(): void
    {
        $parsed = SasUrl::parse('https://contateste.blob.core.windows.net?sig=abc');

        $this->assertSame('', $parsed['container']);
        $this->assertSame('contateste', $parsed['account_name']);
    }

    public function test_trata_o_emulador_com_a_conta_no_path(): void
    {
        $parsed = SasUrl::parse('http://127.0.0.1:10000/devstoreaccount1/meu-container?sig=abc');

        $this->assertSame('http://127.0.0.1:10000/devstoreaccount1', $parsed['account_url']);
        $this->assertSame('devstoreaccount1', $parsed['account_name']);
        $this->assertSame('meu-container', $parsed['container']);
    }

    public function test_localhost_tambem_e_tratado_como_emulador(): void
    {
        $parsed = SasUrl::parse('http://localhost:10000/devstoreaccount1/c?sig=abc');

        $this->assertSame('http://localhost:10000/devstoreaccount1', $parsed['account_url']);
        $this->assertSame('c', $parsed['container']);
    }

    public function test_devolve_null_para_urls_invalidas(): void
    {
        $this->assertNull(SasUrl::parse('https://contateste.blob.core.windows.net/c'));
        $this->assertNull(SasUrl::parse('nao-e-url'));
        $this->assertNull(SasUrl::parse(''));
    }
}
