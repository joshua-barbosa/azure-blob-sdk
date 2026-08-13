<?php

namespace AzureBlob\Tests\Feature;

use AzureBlob\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class CommandsTest extends TestCase
{
    // ========================================================================
    // azure:info
    // ========================================================================

    public function test_info_lista_todas_as_conexoes(): void
    {
        $saida = $this->runCommand('azure:info');

        $this->assertStringContainsString('default', $saida);
        $this->assertStringContainsString('sas', $saida);
        $this->assertStringContainsString('somente-leitura', $saida);
        $this->assertStringContainsString('conexão padrão', $saida);
    }

    public function test_info_marca_a_conexao_somente_leitura(): void
    {
        $saida = $this->runCommand('azure:info', ['--connection' => 'somente-leitura']);

        $this->assertStringContainsString('SOMENTE LEITURA', $saida);
        $this->assertSame(0, $this->lastExitCode);
    }

    public function test_info_com_check_valida_o_acesso(): void
    {
        Http::fake(['*' => Http::response($this->listXml(), 200)]);

        $this->artisan('azure:info', ['--connection' => 'default', '--check' => true])
            ->assertExitCode(0);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'maxresults=1'));
    }

    public function test_info_falha_quando_a_conexao_nao_responde(): void
    {
        Http::fake(['*' => Http::response($this->errorXml('AuthenticationFailed', 'negado'), 403)]);

        $this->artisan('azure:info', ['--connection' => 'default', '--check' => true])
            ->assertExitCode(1);
    }

    // ========================================================================
    // azure:list
    // ========================================================================

    public function test_list_mostra_os_blobs_em_tabela(): void
    {
        Http::fake([
            '*' => Http::response($this->listXml([
                ['name' => '2026/a.pdf', 'size' => 2048],
                ['name' => '2026/b.pdf', 'size' => 1024],
            ]), 200),
        ]);

        $saida = $this->runCommand('azure:list', ['prefix' => '2026/']);

        $this->assertStringContainsString('2026/a.pdf', $saida);
        $this->assertStringContainsString('2.0 KB', $saida);
        $this->assertStringContainsString('2 blob(s)', $saida);
        $this->assertStringContainsString('3.0 KB no total', $saida);
    }

    public function test_list_avisa_quando_nao_ha_nada(): void
    {
        Http::fake(['*' => Http::response($this->listXml(), 200)]);

        $saida = $this->runCommand('azure:list');

        $this->assertStringContainsString('Nenhum blob encontrado', $saida);
        $this->assertSame(0, $this->lastExitCode);
    }

    public function test_list_com_json_devolve_json_valido(): void
    {
        Http::fake(['*' => Http::response($this->listXml([['name' => 'a.pdf', 'size' => 10]]), 200)]);

        // A saída precisa ser consumível por outro processo, sem tabela junto.
        $saida = $this->runCommand('azure:list', ['--json' => true]);

        $this->assertStringContainsString('"name": "a.pdf"', $saida);
        $this->assertSame(0, $this->lastExitCode);
    }

    public function test_list_respeita_max(): void
    {
        Http::fake(['*' => Http::response($this->listXml(), 200)]);

        $this->artisan('azure:list', ['--max' => 7])->assertExitCode(0);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'maxresults=7'));
    }

    public function test_list_shallow_usa_delimitador(): void
    {
        Http::fake(['*' => Http::response($this->listXml([], ['2026/']), 200)]);

        $saida = $this->runCommand('azure:list', ['--shallow' => true]);

        $this->assertStringContainsString('2026/', $saida);
        $this->assertSame(0, $this->lastExitCode);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'delimiter=%2F'));
    }

    public function test_list_usa_a_conexao_e_o_container_informados(): void
    {
        Http::fake(['*' => Http::response($this->listXml(), 200)]);

        $this->artisan('azure:list', ['--connection' => 'sas', '--container' => 'outro'])
            ->assertExitCode(0);

        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            self::ENDPOINT.'/outro?'
        ));
    }

    // ========================================================================
    // azure:download
    // ========================================================================

    public function test_download_grava_o_arquivo(): void
    {
        Http::fake(['*' => Http::response('conteúdo baixado', 200)]);

        $path = sys_get_temp_dir().'/azure-cmd-'.uniqid().'.txt';

        $this->artisan('azure:download', ['blob' => 'a.txt', 'destination' => $path])
            ->assertExitCode(0);

        $this->assertSame('conteúdo baixado', file_get_contents($path));

        unlink($path);
    }

    public function test_download_em_diretorio_usa_o_nome_do_blob(): void
    {
        Http::fake(['*' => Http::response('x', 200)]);

        $directory = sys_get_temp_dir().'/azure-cmd-'.uniqid();
        mkdir($directory);

        $this->artisan('azure:download', ['blob' => 'pasta/relatorio.pdf', 'destination' => $directory])
            ->assertExitCode(0);

        $this->assertFileExists($directory.'/relatorio.pdf');

        unlink($directory.'/relatorio.pdf');
        rmdir($directory);
    }

    public function test_download_com_stdout_escreve_na_saida(): void
    {
        Http::fake(['*' => Http::response('na saída', 200)]);

        $saida = $this->runCommand('azure:download', ['blob' => 'a.txt', '--stdout' => true]);

        $this->assertStringContainsString('na saída', $saida);
        $this->assertSame(0, $this->lastExitCode);
    }

    public function test_download_de_blob_inexistente_falha_com_mensagem(): void
    {
        Http::fake(['*' => Http::response($this->errorXml('BlobNotFound', 'não existe'), 404)]);

        $saida = $this->runCommand('azure:download', ['blob' => 'sumiu.txt', '--stdout' => true]);

        $this->assertStringContainsString('não encontrado', $saida);
        $this->assertSame(1, $this->lastExitCode);
    }

    // ========================================================================
    // azure:upload
    // ========================================================================

    public function test_upload_envia_o_arquivo(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $path = sys_get_temp_dir().'/azure-cmd-'.uniqid().'.txt';
        file_put_contents($path, 'para enviar');

        $this->artisan('azure:upload', ['file' => $path, 'blob' => 'destino/a.txt'])
            ->assertExitCode(0);

        Http::assertSent(fn (Request $request): bool => $request->url() === $this->blobUrl('destino/a.txt')
            && $request->body() === 'para enviar');

        unlink($path);
    }

    public function test_upload_sem_nome_usa_o_basename_do_arquivo(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $path = sys_get_temp_dir().'/azure-cmd-relatorio.pdf';
        file_put_contents($path, 'x');

        $this->artisan('azure:upload', ['file' => $path])->assertExitCode(0);

        Http::assertSent(fn (Request $request): bool => $request->url() === $this->blobUrl('azure-cmd-relatorio.pdf'));

        unlink($path);
    }

    public function test_upload_com_no_overwrite_manda_if_none_match(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $path = sys_get_temp_dir().'/azure-cmd-'.uniqid().'.txt';
        file_put_contents($path, 'x');

        $this->artisan('azure:upload', ['file' => $path, '--no-overwrite' => true])->assertExitCode(0);

        Http::assertSent(fn (Request $request): bool => $request->header('If-None-Match')[0] === '*');

        unlink($path);
    }

    public function test_upload_de_arquivo_inexistente_falha(): void
    {
        Http::fake();

        $saida = $this->runCommand('azure:upload', ['file' => '/nao/existe.txt']);

        $this->assertStringContainsString('não existe ou não pode ser lido', $saida);
        $this->assertSame(1, $this->lastExitCode);
    }

    // ========================================================================
    // azure:delete
    // ========================================================================

    public function test_delete_pede_confirmacao_antes_de_remover(): void
    {
        Http::fake(['*' => Http::response('', 202)]);

        $this->artisan('azure:delete', ['blob' => 'a.txt'])
            ->expectsConfirmation('Remover "a.txt" do container "meu-container"?', 'no')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_delete_com_force_nao_pergunta(): void
    {
        Http::fake(['*' => Http::response('', 202)]);

        $saida = $this->runCommand('azure:delete', ['blob' => 'a.txt', '--force' => true]);

        $this->assertStringContainsString('removido', $saida);
        $this->assertSame(0, $this->lastExitCode);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_delete_recursivo_remove_o_prefixo_inteiro(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->listXml([['name' => '2026/a.pdf'], ['name' => '2026/b.pdf']]), 200)
            ->push('', 202)
            ->push('', 202),
        ]);

        $saida = $this->runCommand('azure:delete', ['blob' => '2026', '--recursive' => true, '--force' => true]);

        $this->assertStringContainsString('2 blob(s) removido(s)', $saida);
        $this->assertSame(0, $this->lastExitCode);
    }

    public function test_delete_em_conexao_somente_leitura_e_bloqueado(): void
    {
        Http::fake();

        $saida = $this->runCommand('azure:delete', [
            'blob' => 'a.txt',
            '--force' => true,
            '--connection' => 'somente-leitura',
        ]);

        $this->assertStringContainsString('somente leitura', $saida);
        $this->assertSame(1, $this->lastExitCode);

        Http::assertNothingSent();
    }

    // ========================================================================
    // azure:copy
    // ========================================================================

    public function test_copy_copia_entre_containers(): void
    {
        Http::fake(['*' => Http::response('', 202, ['x-ms-copy-status' => 'success'])]);

        $saida = $this->runCommand('azure:copy', [
            'source' => 'a.pdf',
            'destination' => 'b.pdf',
            '--to' => 'destino',
        ]);

        $this->assertStringContainsString('Copiado', $saida);
        $this->assertSame(0, $this->lastExitCode);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::ENDPOINT.'/destino/b.pdf');
    }

    public function test_copy_com_move_apaga_a_origem(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('', 202, ['x-ms-copy-status' => 'success'])
            ->push('', 202),
        ]);

        $saida = $this->runCommand('azure:copy', ['source' => 'a.pdf', 'destination' => 'b.pdf', '--move' => true]);

        $this->assertStringContainsString('Movido', $saida);
        $this->assertSame(0, $this->lastExitCode);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    // ========================================================================
    // azure:sas
    // ========================================================================

    public function test_sas_gera_url_assinada_do_blob(): void
    {
        $saida = $this->runCommand('azure:sas', ['blob' => 'a.pdf', '--hours' => 3, '--permissions' => 'rw']);

        $this->assertStringContainsString($this->blobUrl('a.pdf').'?', $saida);
        $this->assertStringContainsString('sp=rw', $saida);
        $this->assertStringContainsString('sr=b', $saida);
        $this->assertStringContainsString('sig=', $saida);
    }

    public function test_sas_sem_blob_assina_o_container(): void
    {
        $saida = $this->runCommand('azure:sas');

        $this->assertStringContainsString('sr=c', $saida);
        $this->assertStringContainsString('sp=rl', $saida);
    }

    public function test_sas_avisa_quando_o_token_e_reaproveitado(): void
    {
        $saida = $this->runCommand('azure:sas', ['blob' => 'a.pdf', '--connection' => 'sas']);

        $this->assertStringContainsString('token do container foi reaproveitado', $saida);
        $this->assertSame(0, $this->lastExitCode);
    }

    // ========================================================================
    // azure:exists
    // ========================================================================

    public function test_exists_mostra_as_propriedades(): void
    {
        Http::fake(['*' => Http::response('', 200, $this->propertyHeaders(4096, 'application/pdf'))]);

        $saida = $this->runCommand('azure:exists', ['blob' => 'a.pdf']);

        $this->assertStringContainsString('4.0 KB', $saida);
        $this->assertStringContainsString('application/pdf', $saida);
        $this->assertStringContainsString('meta.origem', $saida);
    }

    public function test_exists_sai_com_erro_quando_o_blob_nao_existe(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        // O código de saída torna o comando usável em script de shell.
        $saida = $this->runCommand('azure:exists', ['blob' => 'sumiu.pdf']);

        $this->assertStringContainsString('não existe', $saida);
        $this->assertSame(1, $this->lastExitCode);
    }

    public function test_exists_com_json(): void
    {
        Http::fake(['*' => Http::response('', 200, $this->propertyHeaders())]);

        $saida = $this->runCommand('azure:exists', ['blob' => 'a.pdf', '--json' => true]);

        $this->assertStringContainsString('"exists": true', $saida);
        $this->assertSame(0, $this->lastExitCode);
    }
}
