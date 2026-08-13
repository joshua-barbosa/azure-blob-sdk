<?php

namespace AzureBlob\Tests\Unit;

use AzureBlob\Support\SharedKeySigner;
use PHPUnit\Framework\TestCase;

/**
 * O string-to-sign do Shared Key é posicional: qualquer linha fora de lugar
 * produz 403 no Azure e nada mais específico do que isso. Os testes abaixo
 * fixam o formato inteiro em vez de só conferir que "algo foi assinado".
 */
class SharedKeySignerTest extends TestCase
{
    private const ACCOUNT = 'contateste';

    private const KEY = 'Y2hhdmUtZGUtdGVzdGUtc2VjcmV0YS0xMjM0NTY3ODkw';

    private function signer(): SharedKeySigner
    {
        return new SharedKeySigner(self::ACCOUNT, self::KEY);
    }

    public function test_monta_o_string_to_sign_no_formato_documentado(): void
    {
        $stringToSign = $this->signer()->stringToSign(
            'GET',
            'https://contateste.blob.core.windows.net/container/pasta/a.txt',
            ['x-ms-date' => 'Tue, 06 Jan 2026 12:00:00 GMT', 'x-ms-version' => '2022-11-02'],
        );

        $this->assertSame(implode("\n", [
            'GET',
            '',  // Content-Encoding
            '',  // Content-Language
            '',  // Content-Length
            '',  // Content-MD5
            '',  // Content-Type
            '',  // Date — vazio porque usamos x-ms-date
            '',  // If-Modified-Since
            '',  // If-Match
            '',  // If-None-Match
            '',  // If-Unmodified-Since
            '',  // Range
            'x-ms-date:Tue, 06 Jan 2026 12:00:00 GMT',
            'x-ms-version:2022-11-02',
            '/contateste/container/pasta/a.txt',
        ]), $stringToSign);
    }

    public function test_ordena_os_cabecalhos_x_ms_lexicograficamente(): void
    {
        $stringToSign = $this->signer()->stringToSign('PUT', 'https://contateste.blob.core.windows.net/c/b', [
            'x-ms-version' => '2022-11-02',
            'x-ms-blob-type' => 'BlockBlob',
            'x-ms-date' => 'Tue, 06 Jan 2026 12:00:00 GMT',
        ]);

        $this->assertStringContainsString(
            "x-ms-blob-type:BlockBlob\nx-ms-date:Tue, 06 Jan 2026 12:00:00 GMT\nx-ms-version:2022-11-02\n",
            $stringToSign
        );
    }

    public function test_normaliza_nome_e_espacos_dos_cabecalhos_x_ms(): void
    {
        $stringToSign = $this->signer()->stringToSign('PUT', 'https://contateste.blob.core.windows.net/c/b', [
            'X-MS-Meta-Origem' => '  valor    com   espacos  ',
            'x-ms-date' => 'Tue, 06 Jan 2026 12:00:00 GMT',
        ]);

        $this->assertStringContainsString("x-ms-meta-origem:valor com espacos\n", $stringToSign);
    }

    public function test_content_length_zero_entra_como_string_vazia(): void
    {
        $stringToSign = $this->signer()->stringToSign('PUT', 'https://contateste.blob.core.windows.net/c/b', [
            'Content-Length' => '0',
            'x-ms-date' => 'Tue, 06 Jan 2026 12:00:00 GMT',
        ]);

        // A 4ª linha é Content-Length; deve estar vazia mesmo com o cabeçalho presente.
        $this->assertSame('', explode("\n", $stringToSign)[3]);
    }

    public function test_content_length_diferente_de_zero_e_assinado(): void
    {
        $stringToSign = $this->signer()->stringToSign('PUT', 'https://contateste.blob.core.windows.net/c/b', [
            'Content-Length' => '17',
            'Content-Type' => 'text/plain',
            'x-ms-date' => 'Tue, 06 Jan 2026 12:00:00 GMT',
        ]);

        $lines = explode("\n", $stringToSign);

        $this->assertSame('17', $lines[3]);
        $this->assertSame('text/plain', $lines[5]);
    }

    public function test_recurso_canonico_ordena_e_minuscula_os_parametros_de_query(): void
    {
        $stringToSign = $this->signer()->stringToSign(
            'GET',
            'https://contateste.blob.core.windows.net/container?restype=container&COMP=list&maxresults=50',
            ['x-ms-date' => 'Tue, 06 Jan 2026 12:00:00 GMT'],
        );

        $this->assertStringEndsWith(
            "/contateste/container\ncomp:list\nmaxresults:50\nrestype:container",
            $stringToSign
        );
    }

    public function test_recurso_canonico_junta_valores_repetidos_ordenados(): void
    {
        $stringToSign = $this->signer()->stringToSign(
            'GET',
            'https://contateste.blob.core.windows.net/c?include=snapshots&include=metadata',
            ['x-ms-date' => 'Tue, 06 Jan 2026 12:00:00 GMT'],
        );

        $this->assertStringEndsWith("/contateste/c\ninclude:metadata,snapshots", $stringToSign);
    }

    public function test_recurso_canonico_decodifica_os_valores_da_query(): void
    {
        $stringToSign = $this->signer()->stringToSign(
            'PUT',
            'https://contateste.blob.core.windows.net/c/b?comp=block&blockid=YmxvY2stMDAwMDAwMDA%3D',
            ['x-ms-date' => 'Tue, 06 Jan 2026 12:00:00 GMT'],
        );

        $this->assertStringEndsWith("blockid:YmxvY2stMDAwMDAwMDA=\ncomp:block", $stringToSign);
    }

    public function test_assinatura_confere_com_o_hmac_calculado_a_parte(): void
    {
        $headers = ['x-ms-date' => 'Tue, 06 Jan 2026 12:00:00 GMT', 'x-ms-version' => '2022-11-02'];
        $url = 'https://contateste.blob.core.windows.net/container/a.txt';

        $signed = $this->signer()->sign('GET', $url, $headers);

        $expected = base64_encode(hash_hmac(
            'sha256',
            $this->signer()->stringToSign('GET', $url, $headers),
            base64_decode(self::KEY, true),
            true
        ));

        $this->assertSame('SharedKey '.self::ACCOUNT.':'.$expected, $signed['Authorization']);
    }

    public function test_preenche_x_ms_date_e_x_ms_version_quando_ausentes(): void
    {
        $signed = $this->signer()->sign('GET', 'https://contateste.blob.core.windows.net/c/b', []);

        $this->assertArrayHasKey('x-ms-date', $signed);
        $this->assertArrayHasKey('x-ms-version', $signed);
        $this->assertSame('2022-11-02', $signed['x-ms-version']);
        $this->assertMatchesRegularExpression(
            '/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/',
            $signed['x-ms-date']
        );
    }

    public function test_nao_sobrescreve_a_versao_informada_pelo_chamador(): void
    {
        $signed = $this->signer()->sign(
            'GET',
            'https://contateste.blob.core.windows.net/c/b',
            ['x-ms-version' => '2019-12-12'],
            '2022-11-02'
        );

        $this->assertSame('2019-12-12', $signed['x-ms-version']);
    }

    public function test_url_sem_path_assina_a_raiz_da_conta(): void
    {
        $stringToSign = $this->signer()->stringToSign(
            'GET',
            'https://contateste.blob.core.windows.net',
            ['x-ms-date' => 'Tue, 06 Jan 2026 12:00:00 GMT'],
        );

        $this->assertStringEndsWith('/contateste/', $stringToSign);
    }

    public function test_metodo_e_normalizado_para_maiusculas(): void
    {
        $signed = $this->signer()->sign('get', 'https://contateste.blob.core.windows.net/c/b', [
            'x-ms-date' => 'Tue, 06 Jan 2026 12:00:00 GMT',
            'x-ms-version' => '2022-11-02',
        ]);

        $expectedFromUpper = $this->signer()->sign('GET', 'https://contateste.blob.core.windows.net/c/b', [
            'x-ms-date' => 'Tue, 06 Jan 2026 12:00:00 GMT',
            'x-ms-version' => '2022-11-02',
        ]);

        $this->assertSame($expectedFromUpper['Authorization'], $signed['Authorization']);
    }
}
