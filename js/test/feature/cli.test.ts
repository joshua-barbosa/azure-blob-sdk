import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { BlobManager } from '../../src/blob-manager.js';
import type { CliIo } from '../../src/cli/io.js';
import { Printer } from '../../src/cli/io.js';
import { parseDotenv } from '../../src/cli/load-config.js';
import { run } from '../../src/cli/run.js';
import { ACCOUNT, ACCOUNT_URL, FakeAzure, KEY, SAS_TOKEN } from '../helpers/fake-azure.js';

let directory: string;
let azure: FakeAzure;

beforeEach(async () => {
    directory = await mkdtemp(join(tmpdir(), 'azure-blob-cli-'));
    azure = new FakeAzure();
});

afterEach(async () => {
    await rm(directory, { recursive: true, force: true });
});

const ENV = {
    AZURE_STORAGE_NAME: ACCOUNT,
    AZURE_STORAGE_KEY: KEY,
    AZURE_STORAGE_CONTAINER: 'docs',
    AZURE_BLOB_CONNECTIONS: 'publico',
    PUBLICO_AZURE_STORAGE_SAS_URL: `${ACCOUNT_URL}/publico?${SAS_TOKEN}`,
};

interface Result {
    code: number;
    stdout: string;
    stderr: string;
}

async function cli(args: string[], options: { env?: Record<string, string>; confirm?: boolean; color?: boolean } = {}): Promise<Result> {
    let stdout = '';
    let stderr = '';
    const io: CliIo = {
        stdout: { write: (chunk) => (stdout += typeof chunk === 'string' ? chunk : Buffer.from(chunk).toString('utf8')) },
        stderr: { write: (chunk) => (stderr += String(chunk)) },
        env: options.env ?? ENV,
        cwd: directory,
        color: options.color ?? false,
        confirm: async () => options.confirm ?? false,
        createManager: (managerOptions) =>
            new BlobManager({ ...managerOptions, http: { ...(managerOptions.http ?? {}), fetch: azure.fetch } }),
    };

    const code = await run(args, io, '9.9.9');

    return { code, stdout, stderr };
}

describe('ajuda e erros de uso', () => {
    it('mostra ajuda, versão e recusa comando desconhecido', async () => {
        expect((await cli([])).stdout).toContain('Comandos:');
        expect((await cli(['--version'])).stdout).toBe('9.9.9\n');
        expect((await cli(['list', '--help'])).stdout).toContain('azure-blob list [prefixo]');
        expect((await cli(['delete', '-h'])).stdout).toContain('cancela');

        const unknown = await cli(['voar']);
        expect(unknown.code).toBe(1);
        expect(unknown.stderr).toContain('Comando desconhecido: "voar"');
    });

    it('opção inválida e argumento faltando saem com 1', async () => {
        const invalid = await cli(['list', '--nao-existe']);
        expect(invalid.code).toBe(1);
        expect(invalid.stdout).toContain('Uso: azure-blob list');

        const missing = await cli(['download']);
        expect(missing.code).toBe(1);
        expect(missing.stderr).toContain('argumento "blob" é obrigatório');
    });
});

describe('info', () => {
    it('lista conexões e valida com --check', async () => {
        const result = await cli(['info', '--check']);

        expect(result.code).toBe(0);
        expect(result.stdout).toContain('default *');
        expect(result.stdout).toContain('Auth: conta+chave');
        expect(result.stdout).toContain('Auth: SAS URL');
    });

    it('sai com 1 quando uma conexão falha', async () => {
        const result = await cli(['info', '--check'], { env: { ...ENV, AZURE_STORAGE_KEY: 'b3V0cmE=' } });

        expect(result.code).toBe(1);
        expect(result.stdout).toContain('HTTP 403');

        const broken = await cli(['info', '--connection=nenhuma']);
        expect(broken.code).toBe(1);
        expect(broken.stdout).toContain('não está entre as conexões');
    });
});

describe('list, exists e sas', () => {
    beforeEach(() => {
        azure.put('docs/a/1.txt', 'um');
        azure.put('docs/a/sub/2.txt', 'dois', 'text/plain', { origem: 'cli' });
    });

    it('list em tabela, rasa e em JSON', async () => {
        const table = await cli(['list', 'a/']);
        expect(table.stdout).toMatch(/a\/1\.txt\s+\| 2 B/);
        expect(table.stdout).toContain('2 blob(s), 6 B no total — container "docs".');

        const shallow = await cli(['list', 'a', '--shallow']);
        expect(shallow.stdout).toContain('a/sub/ ');
        expect(shallow.stdout).toContain('pasta');

        const json = JSON.parse((await cli(['list', '--all', '--json', '-q'])).stdout) as Array<{ name: string }>;
        expect(json.map((item) => item.name)).toEqual(['a/1.txt', 'a/sub/2.txt']);

        expect((await cli(['list', 'z/'])).stdout).toContain('Nenhum blob encontrado com o prefixo "z/"');
        expect((await cli(['list', 'a/', '--quiet'])).stdout).toBe('');
    });

    it('exists sai com 1 quando falta e mostra propriedades quando existe', async () => {
        const missing = await cli(['exists', 'nada.txt']);
        expect(missing.code).toBe(1);
        expect(missing.stderr).toContain('"nada.txt" não existe no container "docs"');
        expect((await cli(['exists', 'nada.txt', '--json'])).stdout).toBe('{"exists":false,"name":"nada.txt"}\n');

        const found = await cli(['exists', 'a/sub/2.txt']);
        expect(found.code).toBe(0);
        expect(found.stdout).toContain('4 B (4 bytes)');
        expect(found.stdout).toContain('meta.origem');

        const json = JSON.parse((await cli(['exists', 'a/sub/2.txt', '--json'])).stdout) as Record<string, unknown>;
        expect(json).toMatchObject({ exists: true, name: 'a/sub/2.txt', metadata: { origem: 'cli' } });
    });

    it('sas assina com chave e avisa quando reaproveita o token', async () => {
        const signed = await cli(['sas', 'a/1.txt', '--hours=2', '--permissions=wr']);
        expect(signed.stdout).toMatch(/^https:\/\/contateste\.blob\.core\.windows\.net\/docs\/a\/1\.txt\?sv=.*sp=rw/);

        expect((await cli(['sas'])).stdout).toContain('sr=c&sp=rl');

        const reused = await cli(['sas', 'x.txt', '--connection', 'publico']);
        expect(reused.stdout).toBe(`${ACCOUNT_URL}/publico/x.txt?${SAS_TOKEN}\n`);
        expect(reused.stderr).toContain('token do container foi reaproveitado');
    });
});

describe('download, upload, copy e delete', () => {
    it('download para diretório, arquivo e stdout', async () => {
        azure.put('docs/pasta/a.txt', 'conteúdo');

        const toDirectory = await cli(['download', 'pasta/a.txt', '.']);
        expect(toDirectory.code).toBe(0);
        expect(await readFile(join(directory, 'a.txt'), 'utf8')).toBe('conteúdo');

        await cli(['download', 'pasta/a.txt', 'b.txt']);
        expect(await readFile(join(directory, 'b.txt'), 'utf8')).toBe('conteúdo');

        expect((await cli(['download', 'pasta/a.txt', '--stdout'])).stdout).toBe('conteúdo');

        const missing = await cli(['download', 'nada.txt', '--stdout']);
        expect(missing.code).toBe(1);
        expect(missing.stderr).toContain('Código do Azure: BlobNotFound');
    });

    it('upload usa o basename, respeita --content-type e --no-overwrite', async () => {
        await writeFile(join(directory, 'nota.pdf'), '%PDF');

        expect((await cli(['upload', 'nota.pdf'])).stdout).toContain(`${ACCOUNT_URL}/docs/nota.pdf`);
        expect(azure.blobs.get('docs/nota.pdf')?.contentType).toBe('application/pdf');

        await cli(['upload', join(directory, 'nota.pdf'), 'outra/nota', '--content-type=text/plain']);
        expect(azure.blobs.get('docs/outra/nota')?.contentType).toBe('text/plain');

        const conflict = await cli(['upload', 'nota.pdf', '--no-overwrite']);
        expect(conflict.code).toBe(1);
        expect(conflict.stderr).toContain('BlobAlreadyExists');
    });

    it('copy, move e --container', async () => {
        azure.put('docs/a.txt', 'a');

        expect((await cli(['copy', 'a.txt', 'b.txt', '--to=backup', '--wait'])).stdout).toContain('Copiado a.txt → b.txt');
        expect(azure.blobs.has('backup/b.txt')).toBe(true);

        expect((await cli(['copy', 'b.txt', 'c.txt', '--container=backup', '--move'])).stdout).toContain('Movido');
        expect(azure.blobs.has('backup/b.txt')).toBe(false);
        expect(azure.blobs.has('backup/c.txt')).toBe(true);
    });

    it('delete pede confirmação, e --force/--recursive dispensam', async () => {
        azure.put('docs/a.txt', 'a');
        azure.put('docs/tmp/1.txt', '1');
        azure.put('docs/tmp/2.txt', '2');

        expect((await cli(['delete', 'a.txt'])).stdout).toContain('Cancelado.');
        expect(azure.blobs.has('docs/a.txt')).toBe(true);

        expect((await cli(['delete', 'a.txt'], { confirm: true })).stdout).toContain('"a.txt" removido.');
        expect((await cli(['delete', 'a.txt', '-f'])).stdout).toContain('já não existia');
        expect((await cli(['delete', 'tmp', '--recursive', '--force'])).stdout).toContain('2 blob(s) removido(s)');
    });
});

describe('configuração', () => {
    it('lê .env do diretório atual, com o ambiente real tendo precedência', async () => {
        await writeFile(
            join(directory, '.env'),
            `# comentário\nexport AZURE_STORAGE_NAME=${ACCOUNT}\nAZURE_STORAGE_KEY="${KEY}"\nAZURE_STORAGE_CONTAINER=do-arquivo # fim\n`,
        );

        expect((await cli(['info'], { env: {} })).stdout).toContain('Container: do-arquivo');
        expect((await cli(['info'], { env: { AZURE_STORAGE_CONTAINER: 'do-ambiente' } })).stdout).toContain(
            'Container: do-ambiente',
        );
    });

    it('--env-file e --config (JSON e módulo)', async () => {
        await writeFile(join(directory, 'outro.env'), `AZURE_STORAGE_SAS_URL=${ACCOUNT_URL}/via-env?${SAS_TOKEN}\n`);
        await writeFile(
            join(directory, 'azure.json'),
            JSON.stringify({ connections: { default: { sas_url: `${ACCOUNT_URL}/via-json?${SAS_TOKEN}` } } }),
        );
        await writeFile(
            join(directory, 'azure.mjs'),
            `export default { connections: { default: { sasUrl: '${ACCOUNT_URL}/via-mjs?${SAS_TOKEN}' } } };`,
        );

        expect((await cli(['info', '--env-file', 'outro.env'], { env: {} })).stdout).toContain('Container: via-env');
        expect((await cli(['info', '--config', 'azure.json'])).stdout).toContain('Container: via-json');
        expect((await cli(['info', '--config', join(directory, 'azure.mjs')])).stdout).toContain('Container: via-mjs');

        expect((await cli(['info', '--config', 'nao.json'])).stderr).toContain('não encontrado');
        expect((await cli(['info', '--env-file', 'nao.env'])).stderr).toContain('não encontrado');

        await writeFile(join(directory, 'quebrado.json'), '{');
        expect((await cli(['info', '--config', 'quebrado.json'])).stderr).toContain('não foi possível ler');
    });

    it('sem credenciais o erro é legível', async () => {
        const result = await cli(['list'], { env: {} });

        expect(result.code).toBe(1);
        expect(result.stderr).toContain('nenhuma credencial configurada');
    });

    it('parseDotenv ignora linhas inválidas', () => {
        expect(parseDotenv("A=1\n=x\nsem-igual\nB='dois # não é comentário'\r\nC=três # comentário")).toEqual({
            A: '1',
            B: 'dois # não é comentário',
            C: 'três',
        });
    });

    it('Printer colore só quando permitido', () => {
        let written = '';
        const io = { stdout: { write: (chunk: string) => (written += chunk) }, color: true } as unknown as CliIo;

        new Printer(io, false).line('ok', 'green');
        expect(written).toBe('\u001b[32mok\u001b[39m\n');
    });
});
