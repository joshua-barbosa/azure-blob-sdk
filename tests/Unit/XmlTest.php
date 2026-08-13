<?php

namespace AzureBlob\Tests\Unit;

use AzureBlob\Exceptions\AzureBlobException;
use AzureBlob\Support\Xml;
use PHPUnit\Framework\TestCase;

class XmlTest extends TestCase
{
    public function test_extrai_codigo_e_mensagem_de_um_erro_do_azure(): void
    {
        $error = Xml::error(
            '<?xml version="1.0"?><Error><Code>BlobNotFound</Code>'
            ."<Message>The specified blob does not exist.\nRequestId:abc\nTime:2026-01-06</Message></Error>"
        );

        $this->assertSame('BlobNotFound', $error['code']);
        // O rastro de RequestId/Time é cortado: só a primeira linha é legível.
        $this->assertSame('The specified blob does not exist.', $error['message']);
    }

    public function test_corpo_vazio_ou_sem_erro_devolve_nulos(): void
    {
        $this->assertSame(['code' => null, 'message' => null], Xml::error(''));
        $this->assertSame(['code' => null, 'message' => null], Xml::error('<Outro/>'));
    }

    public function test_xml_malformado_no_erro_nao_estoura(): void
    {
        $this->assertSame(['code' => null, 'message' => null], Xml::error('<Error><Code>abc'));
    }

    public function test_parse_rejeita_conteudo_vazio(): void
    {
        $this->expectException(AzureBlobException::class);
        $this->expectExceptionMessage('resposta XML vazia');

        Xml::parse('   ');
    }

    public function test_parse_rejeita_xml_invalido(): void
    {
        $this->expectException(AzureBlobException::class);
        $this->expectExceptionMessage('XML inválido');

        Xml::parse('<a><b></a>');
    }

    public function test_nao_expande_entidades_externas(): void
    {
        // Sem LIBXML_NOENT a entidade fica literal; o arquivo não é lido.
        $xml = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><r>&xxe;</r>';

        $parsed = Xml::parse($xml);

        $this->assertStringNotContainsString('root:', (string) $parsed);
    }

    public function test_monta_o_corpo_de_put_block_list(): void
    {
        $body = Xml::blockList(['YmxvY2stMDAwMDAwMDA=', 'YmxvY2stMDAwMDAwMDE=']);

        $this->assertStringContainsString('<BlockList>', $body);
        $this->assertStringContainsString('<Latest>YmxvY2stMDAwMDAwMDA=</Latest>', $body);
        $this->assertStringContainsString('<Latest>YmxvY2stMDAwMDAwMDE=</Latest>', $body);
        $this->assertStringEndsWith('</BlockList>', $body);
    }
}
