<?php

namespace AzureBlob\Tests\Unit;

use AzureBlob\Support\ConnectionString;
use PHPUnit\Framework\TestCase;

class ConnectionStringTest extends TestCase
{
    public function test_extrai_os_campos_e_deriva_o_endpoint(): void
    {
        $parsed = ConnectionString::parse(
            'DefaultEndpointsProtocol=https;AccountName=contateste;AccountKey=abc123==;EndpointSuffix=core.windows.net'
        );

        $this->assertSame('contateste', $parsed['account_name']);
        $this->assertSame('abc123==', $parsed['account_key']);
        $this->assertSame('https://contateste.blob.core.windows.net', $parsed['endpoint']);
    }

    public function test_preserva_o_igual_final_da_chave_base64(): void
    {
        // A AccountKey é base64 e quase sempre termina em "="; um split ingênuo
        // por "=" cortaria a chave e toda assinatura sairia errada.
        $parsed = ConnectionString::parse('AccountName=c;AccountKey=YWJjZGVmZ2hpams=;');

        $this->assertSame('YWJjZGVmZ2hpams=', $parsed['account_key']);
    }

    public function test_chaves_sao_case_insensitive(): void
    {
        $parsed = ConnectionString::parse('accountname=contateste;ACCOUNTKEY=xyz;');

        $this->assertSame('contateste', $parsed['account_name']);
        $this->assertSame('xyz', $parsed['account_key']);
    }

    public function test_blob_endpoint_explicito_vence_o_derivado(): void
    {
        $parsed = ConnectionString::parse(
            'AccountName=devstoreaccount1;AccountKey=x;BlobEndpoint=http://127.0.0.1:10000/devstoreaccount1/;'
        );

        $this->assertSame('http://127.0.0.1:10000/devstoreaccount1', $parsed['endpoint']);
    }

    public function test_extrai_shared_access_signature(): void
    {
        $parsed = ConnectionString::parse('BlobEndpoint=https://c.blob.core.windows.net;SharedAccessSignature=?sp=r&sig=x');

        $this->assertSame('sp=r&sig=x', $parsed['sas_token']);
        $this->assertSame('', $parsed['account_key']);
    }

    public function test_usa_o_sufixo_informado_quando_a_string_nao_traz_um(): void
    {
        $parsed = ConnectionString::parse('AccountName=c;AccountKey=x', 'core.chinacloudapi.cn');

        $this->assertSame('https://c.blob.core.chinacloudapi.cn', $parsed['endpoint']);
    }

    public function test_segmentos_malformados_sao_ignorados(): void
    {
        $parsed = ConnectionString::parse(';;AccountName=c;lixo;=semchave;AccountKey=x;');

        $this->assertSame('c', $parsed['account_name']);
        $this->assertSame('x', $parsed['account_key']);
    }
}
