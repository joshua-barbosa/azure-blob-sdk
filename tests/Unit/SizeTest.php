<?php

namespace AzureBlob\Tests\Unit;

use AzureBlob\Results\Size;
use PHPUnit\Framework\TestCase;

class SizeTest extends TestCase
{
    public function test_formata_cada_unidade(): void
    {
        $this->assertSame('0 B', Size::human(0));
        $this->assertSame('512 B', Size::human(512));
        $this->assertSame('1.0 KB', Size::human(1024));
        $this->assertSame('1.5 KB', Size::human(1536));
        $this->assertSame('1.0 MB', Size::human(1048576));
        $this->assertSame('2.5 GB', Size::human(2684354560));
    }

    public function test_bytes_nao_ganham_casas_decimais(): void
    {
        $this->assertSame('1023 B', Size::human(1023));
    }

    public function test_valores_negativos_viram_zero(): void
    {
        $this->assertSame('0 B', Size::human(-10));
    }

    public function test_precisao_e_configuravel(): void
    {
        $this->assertSame('1.50 KB', Size::human(1536, 2));
    }
}
