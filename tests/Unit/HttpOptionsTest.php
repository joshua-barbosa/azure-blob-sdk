<?php

namespace AzureBlob\Tests\Unit;

use AzureBlob\Support\Config;
use AzureBlob\Support\HttpOptions;
use PHPUnit\Framework\TestCase;

class HttpOptionsTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $http
     */
    private function config(array $http): Config
    {
        return Config::fromArray([
            'name' => 'contateste',
            'key' => 'Y2hhdmU=',
            'container' => 'c',
            'http' => $http,
        ]);
    }

    public function test_sem_proxy_configurado_a_chave_nao_e_enviada(): void
    {
        $options = HttpOptions::fromConfig($this->config([]));

        $this->assertArrayNotHasKey('proxy', $options);
        $this->assertSame(10, $options['connect_timeout']);
    }

    public function test_proxy_como_string_e_repassado(): void
    {
        $options = HttpOptions::fromConfig($this->config(['proxy' => 'http://proxy.empresa:8080']));

        $this->assertSame('http://proxy.empresa:8080', $options['proxy']);
    }

    public function test_proxy_por_esquema_com_lista_de_excecoes(): void
    {
        $options = HttpOptions::fromConfig($this->config([
            'proxy' => ['http' => 'http://p:8080', 'https' => 'http://p:8443', 'no' => 'localhost, .interno'],
        ]));

        $this->assertSame([
            'http' => 'http://p:8080',
            'https' => 'http://p:8443',
            'no' => ['localhost', '.interno'],
        ], $options['proxy']);
    }

    public function test_apenas_no_sem_proxy_nao_tem_efeito(): void
    {
        $options = HttpOptions::fromConfig($this->config(['proxy' => ['no' => 'localhost']]));

        $this->assertArrayNotHasKey('proxy', $options);
    }

    public function test_verify_vindo_do_env_como_string_e_convertido(): void
    {
        // Sem isso, "false" (string) seria lido pelo Guzzle como caminho de CA bundle.
        $this->assertFalse(HttpOptions::fromConfig($this->config(['verify' => 'false']))['verify']);
        $this->assertTrue(HttpOptions::fromConfig($this->config(['verify' => 'true']))['verify']);
        $this->assertFalse(HttpOptions::fromConfig($this->config(['verify' => false]))['verify']);
    }

    public function test_verify_com_caminho_de_ca_bundle_e_preservado(): void
    {
        $options = HttpOptions::fromConfig($this->config(['verify' => '/etc/ssl/ca.pem']));

        $this->assertSame('/etc/ssl/ca.pem', $options['verify']);
    }
}
