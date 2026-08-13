<?php

namespace AzureBlob\Tests\Unit;

use AzureBlob\Support\SasBuilder;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class SasBuilderTest extends TestCase
{
    private const ACCOUNT = 'contateste';

    private const KEY = 'Y2hhdmUtZGUtdGVzdGUtc2VjcmV0YS0xMjM0NTY3ODkw';

    private function builder(): SasBuilder
    {
        return new SasBuilder(self::ACCOUNT, self::KEY, '2022-11-02');
    }

    /** @return array<string,string> */
    private function query(string $token): array
    {
        parse_str($token, $parsed);

        return array_map('strval', $parsed);
    }

    public function test_gera_os_parametros_esperados_para_um_blob(): void
    {
        $expiry = new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC'));

        $query = $this->query($this->builder()->forBlob('meu-container', 'pasta/a.pdf', $expiry, 'r'));

        $this->assertSame('2022-11-02', $query['sv']);
        $this->assertSame('b', $query['sr']);
        $this->assertSame('r', $query['sp']);
        $this->assertSame('2026-06-01T12:00:00Z', $query['se']);
        $this->assertNotEmpty($query['sig']);
        $this->assertArrayNotHasKey('st', $query);
    }

    public function test_container_usa_signed_resource_c(): void
    {
        $query = $this->query($this->builder()->forContainer('meu-container', 2, 'rl'));

        $this->assertSame('c', $query['sr']);
        $this->assertSame('rl', $query['sp']);
    }

    public function test_assinatura_confere_com_o_string_to_sign_documentado(): void
    {
        $expiry = new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC'));

        $query = $this->query($this->builder()->forBlob('meu-container', 'pasta/a.pdf', $expiry, 'rw'));

        // Layout posicional de sv >= 2020-12-06, reconstruído aqui à mão.
        $stringToSign = implode("\n", [
            'rw',
            '',
            '2026-06-01T12:00:00Z',
            '/blob/contateste/meu-container/pasta/a.pdf',
            '',
            '',
            '',
            '2022-11-02',
            'b',
            '', '', '', '', '', '', '',
        ]);

        $this->assertSame(
            base64_encode(hash_hmac('sha256', $stringToSign, base64_decode(self::KEY, true), true)),
            $query['sig']
        );
    }

    public function test_horas_viram_expiracao_a_partir_de_agora(): void
    {
        $query = $this->query($this->builder()->forBlob('c', 'a.txt', 3));

        $expiry = new DateTimeImmutable($query['se']);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $difference = $expiry->getTimestamp() - $now->getTimestamp();

        $this->assertGreaterThan(3 * 3600 - 60, $difference);
        $this->assertLessThanOrEqual(3 * 3600 + 5, $difference);
    }

    public function test_inclui_st_e_sip_quando_informados(): void
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        $query = $this->query($this->builder()->forBlob('c', 'a.txt', 1, 'r', $start, '10.0.0.1'));

        $this->assertSame('2026-01-01T00:00:00Z', $query['st']);
        $this->assertSame('10.0.0.1', $query['sip']);
    }

    public function test_permissoes_sao_reordenadas_na_ordem_canonica(): void
    {
        // O Azure valida "sp" posicionalmente: "wr" seria rejeitado.
        $this->assertSame('rw', SasBuilder::normalizePermissions('wr'));
        $this->assertSame('racwd', SasBuilder::normalizePermissions('dwcar'));
        $this->assertSame('rl', SasBuilder::normalizePermissions('lr'));
    }

    public function test_permissoes_duplicadas_e_desconhecidas_sao_descartadas(): void
    {
        $this->assertSame('rw', SasBuilder::normalizePermissions('rrwwZZ'));
        $this->assertSame('r', SasBuilder::normalizePermissions('???'));
        $this->assertSame('r', SasBuilder::normalizePermissions(''));
    }

    public function test_permissoes_em_maiusculas_sao_aceitas(): void
    {
        $this->assertSame('rw', SasBuilder::normalizePermissions('RW'));
    }

    public function test_expiracao_em_horas_tem_piso_de_uma_hora(): void
    {
        $query = $this->query($this->builder()->forBlob('c', 'a.txt', 0));

        $difference = (new DateTimeImmutable($query['se']))->getTimestamp() - time();

        $this->assertGreaterThan(3500, $difference);
    }

    public function test_barras_extras_no_container_nao_afetam_a_assinatura(): void
    {
        $expiry = new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC'));

        $this->assertSame(
            $this->query($this->builder()->forBlob('/meu-container/', 'a.txt', $expiry))['sig'],
            $this->query($this->builder()->forBlob('meu-container', '/a.txt', $expiry))['sig'],
        );
    }
}
