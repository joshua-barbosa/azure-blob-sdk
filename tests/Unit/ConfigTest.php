<?php

namespace AzureBlob\Tests\Unit;

use AzureBlob\Exceptions\ConfigurationException;
use AzureBlob\Support\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private const KEY = 'Y2hhdmUtZGUtdGVzdGUtc2VjcmV0YS0xMjM0NTY3ODkw';

    // ========================================================================
    // Modo SAS URL
    // ========================================================================

    public function test_sas_url_preenche_endpoint_conta_container_e_token(): void
    {
        $config = Config::fromArray([
            'sas_url' => 'https://contateste.blob.core.windows.net/apostilas?sp=racwdl&sig=abc123',
        ], 'apostilas');

        $this->assertSame(Config::AUTH_SAS_URL, $config->authMode);
        $this->assertSame('https://contateste.blob.core.windows.net', $config->accountUrl);
        $this->assertSame('contateste', $config->accountName);
        $this->assertSame('apostilas', $config->container);
        $this->assertSame('sp=racwdl&sig=abc123', $config->sasToken);
        $this->assertTrue($config->usesSasToken());
        $this->assertFalse($config->canSign());
    }

    public function test_container_explicito_vence_o_da_sas_url(): void
    {
        $config = Config::fromArray([
            'sas_url' => 'https://contateste.blob.core.windows.net/da-url?sig=abc',
            'container' => 'explicito',
        ]);

        $this->assertSame('explicito', $config->container);
    }

    public function test_sas_url_sem_query_string_e_rejeitada(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"sas_url" inválida');

        Config::fromArray(['sas_url' => 'https://contateste.blob.core.windows.net/container'], 'x');
    }

    public function test_sas_url_sem_container_na_url_e_sem_container_configurado_falha(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('container não configurado');

        Config::fromArray(['sas_url' => 'https://contateste.blob.core.windows.net?sig=abc'], 'x');
    }

    // ========================================================================
    // Modo connection string
    // ========================================================================

    public function test_connection_string_extrai_conta_chave_e_endpoint(): void
    {
        $config = Config::fromArray([
            'connection_string' => 'DefaultEndpointsProtocol=https;AccountName=contateste;AccountKey='
                .self::KEY.';EndpointSuffix=core.windows.net',
            'container' => 'meu-container',
        ]);

        $this->assertSame(Config::AUTH_CONNECTION_STRING, $config->authMode);
        $this->assertSame('contateste', $config->accountName);
        $this->assertSame(self::KEY, $config->accountKey);
        $this->assertSame('https://contateste.blob.core.windows.net', $config->accountUrl);
        $this->assertTrue($config->canSign());
        $this->assertFalse($config->usesSasToken());
    }

    public function test_connection_string_respeita_blob_endpoint_explicito(): void
    {
        $config = Config::fromArray([
            'connection_string' => 'AccountName=devstoreaccount1;AccountKey='.self::KEY
                .';BlobEndpoint=http://127.0.0.1:10000/devstoreaccount1;',
            'container' => 'c',
        ]);

        $this->assertSame('http://127.0.0.1:10000/devstoreaccount1', $config->accountUrl);
    }

    public function test_connection_string_apenas_com_sas_entra_em_modo_sas(): void
    {
        $config = Config::fromArray([
            'connection_string' => 'BlobEndpoint=https://contateste.blob.core.windows.net;'
                .'SharedAccessSignature=sp=r&sig=xyz',
            'container' => 'c',
        ]);

        $this->assertSame(Config::AUTH_SAS_URL, $config->authMode);
        $this->assertSame('sp=r&sig=xyz', $config->sasToken);
        $this->assertTrue($config->usesSasToken());
    }

    public function test_connection_string_sem_container_falha(): void
    {
        $this->expectException(ConfigurationException::class);

        Config::fromArray([
            'connection_string' => 'AccountName=contateste;AccountKey='.self::KEY,
        ], 'sem-container');
    }

    // ========================================================================
    // Modo conta + chave
    // ========================================================================

    public function test_conta_e_chave_derivam_o_endpoint(): void
    {
        $config = Config::fromArray([
            'name' => 'contateste',
            'key' => self::KEY,
            'container' => 'meu-container',
        ]);

        $this->assertSame(Config::AUTH_ACCOUNT_KEY, $config->authMode);
        $this->assertSame('https://contateste.blob.core.windows.net', $config->accountUrl);
    }

    public function test_endpoint_suffix_customizado_e_usado(): void
    {
        $config = Config::fromArray([
            'name' => 'contateste',
            'key' => self::KEY,
            'container' => 'c',
            'endpoint_suffix' => 'core.chinacloudapi.cn',
        ]);

        $this->assertSame('https://contateste.blob.core.chinacloudapi.cn', $config->accountUrl);
    }

    public function test_url_explicita_vence_a_derivada(): void
    {
        $config = Config::fromArray([
            'name' => 'contateste',
            'key' => self::KEY,
            'container' => 'c',
            'url' => 'https://cdn.exemplo.com.br/',
        ]);

        $this->assertSame('https://cdn.exemplo.com.br', $config->accountUrl);
    }

    public function test_sem_credencial_alguma_falha_com_mensagem_util(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('nenhuma credencial configurada');

        Config::fromArray(['container' => 'c'], 'vazia');
    }

    public function test_strings_vazias_contam_como_ausencia_de_credencial(): void
    {
        $this->expectException(ConfigurationException::class);

        // env() devolve '' com frequência; tratar como valor quebraria a resolução.
        Config::fromArray(['sas_url' => '   ', 'name' => '', 'key' => '""', 'container' => 'c'], 'vazia');
    }

    // ========================================================================
    // Precedência e derivados
    // ========================================================================

    public function test_sas_url_tem_precedencia_sobre_connection_string_e_chave(): void
    {
        $config = Config::fromArray([
            'sas_url' => 'https://outra.blob.core.windows.net/c1?sig=abc',
            'connection_string' => 'AccountName=contateste;AccountKey='.self::KEY,
            'name' => 'terceira',
            'key' => self::KEY,
        ]);

        $this->assertSame(Config::AUTH_SAS_URL, $config->authMode);
        $this->assertSame('https://outra.blob.core.windows.net', $config->accountUrl);
        // O nome explícito continua valendo; a URL só preenche o que falta.
        $this->assertSame('terceira', $config->accountName);
    }

    public function test_readonly_aceita_string_do_env(): void
    {
        foreach (['true', '1', 'yes', 'on'] as $value) {
            $this->assertTrue(Config::fromArray($this->base(['readonly' => $value]))->readonly, $value);
        }

        foreach (['false', '0', 'no', ''] as $value) {
            $this->assertFalse(Config::fromArray($this->base(['readonly' => $value]))->readonly, $value);
        }
    }

    public function test_block_size_tem_piso_de_1_mib_e_teto_de_256_mib(): void
    {
        $this->assertSame(1048576, Config::fromArray($this->base(['block_size' => 1024]))->blockSize);
        $this->assertSame(Config::MAX_SINGLE_PUT, Config::fromArray($this->base(['block_size' => PHP_INT_MAX]))->blockSize);
        $this->assertSame(8388608, Config::fromArray($this->base(['block_size' => 8388608]))->blockSize);
    }

    public function test_valores_invalidos_caem_no_padrao(): void
    {
        $config = Config::fromArray($this->base(['max_download_size' => -5]));

        $this->assertSame(Config::DEFAULTS['max_download_size'], $config->maxDownloadSize);
    }

    public function test_http_e_logging_globais_sao_mesclados_e_sobrescritos(): void
    {
        $config = Config::fromArray(
            $this->base(['http' => ['timeout' => 5]]),
            'x',
            ['http' => ['timeout' => 90, 'connect_timeout' => 30], 'logging' => ['channel' => 'global']],
        );

        // A conexão vence o global, que vence o padrão do pacote.
        $this->assertSame(5, $config->timeout());
        $this->assertSame(30, $config->connectTimeout());
        $this->assertSame('global', $config->logging['channel']);
    }

    public function test_timeouts_tem_piso_de_um_segundo(): void
    {
        $config = Config::fromArray($this->base(['http' => ['timeout' => 0, 'connect_timeout' => -1]]));

        $this->assertSame(1, $config->timeout());
        $this->assertSame(1, $config->connectTimeout());
    }

    public function test_with_container_devolve_nova_instancia_sem_alterar_a_original(): void
    {
        $config = Config::fromArray($this->base());
        $outro = $config->withContainer('outro');

        $this->assertNotSame($config, $outro);
        $this->assertSame('meu-container', $config->container);
        $this->assertSame('outro', $outro->container);
    }

    public function test_with_container_devolve_a_mesma_instancia_quando_nao_ha_mudanca(): void
    {
        $config = Config::fromArray($this->base());

        $this->assertSame($config, $config->withContainer(null));
        $this->assertSame($config, $config->withContainer('meu-container'));
        $this->assertSame($config, $config->withContainer('  '));
    }

    public function test_read_only_devolve_copia_travada(): void
    {
        $config = Config::fromArray($this->base());
        $travada = $config->readOnly();

        $this->assertFalse($config->readonly);
        $this->assertTrue($travada->readonly);
        $this->assertSame($travada, $travada->readOnly());
    }

    public function test_blob_url_codifica_cada_segmento_preservando_barras(): void
    {
        $config = Config::fromArray($this->base());

        $this->assertSame(
            'https://contateste.blob.core.windows.net/meu-container/pasta%20com%20espaco/a%2Bb.pdf',
            $config->blobUrl('pasta com espaco/a+b.pdf')
        );
    }

    public function test_describe_resume_a_conexao(): void
    {
        $config = Config::fromArray($this->base(['readonly' => true]));

        $this->assertSame(
            'Conta: contateste | Container: meu-container | Auth: conta+chave [SOMENTE LEITURA]',
            $config->describe()
        );
    }

    public function test_mensagem_de_assinatura_indisponivel_orienta_a_correcao(): void
    {
        $exception = ConfigurationException::signingUnavailable('apostilas');

        $this->assertStringContainsString('apostilas', $exception->getMessage());
        $this->assertStringContainsString('chave da conta', $exception->getMessage());
    }

    public function test_mensagem_de_conexao_desconhecida_aponta_o_arquivo_de_config(): void
    {
        $this->assertStringContainsString(
            'config/azure-blob.php',
            ConfigurationException::unknownConnection('sumida')->getMessage()
        );
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function base(array $overrides = []): array
    {
        return array_merge([
            'name' => 'contateste',
            'key' => self::KEY,
            'container' => 'meu-container',
        ], $overrides);
    }
}
