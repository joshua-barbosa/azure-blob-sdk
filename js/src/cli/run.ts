import { parseArgs } from 'node:util';
import { AzureBlobError } from '../errors.js';
import { type Command, COMMANDS, type OptionSpec } from './commands.js';
import { type CliIo, Printer } from './io.js';
import { loadOptions } from './load-config.js';

/**
 * Ponto de entrada do CLI `azure-blob`. Devolve o código de saída em vez de
 * encerrar o processo, para ser testável.
 */

const GLOBAL_OPTIONS: OptionSpec = {
    connection: { type: 'string', short: 'c' },
    container: { type: 'string' },
    config: { type: 'string' },
    'env-file': { type: 'string' },
    quiet: { type: 'boolean', short: 'q' },
    help: { type: 'boolean', short: 'h' },
};

export async function run(argv: readonly string[], io: CliIo, version = '0.0.0'): Promise<number> {
    const [name, ...rest] = argv;
    const out = new Printer(io, false);

    if (name === '--version' || name === '-v') {
        out.line(version);

        return 0;
    }

    if (name === undefined || name === '--help' || name === '-h' || name === 'help') {
        out.line(help(version));

        return 0;
    }

    const command = COMMANDS[name];

    if (command === undefined) {
        out.error(`Comando desconhecido: "${name}".`);
        out.line(help(version));

        return 1;
    }

    let parsed: { values: Record<string, string | boolean | undefined>; positionals: string[] };

    try {
        parsed = parseArgs({
            args: [...rest],
            options: { ...GLOBAL_OPTIONS, ...command.options },
            allowPositionals: true,
            strict: true,
        }) as typeof parsed;
    } catch (error) {
        out.error((error as Error).message);
        out.line(`Uso: azure-blob ${command.usage}`);

        return 1;
    }

    if (parsed.values['help'] === true) {
        out.line(commandHelp(name, command));

        return 0;
    }

    return execute(command, parsed, io);
}

async function execute(
    command: Command,
    parsed: { values: Record<string, string | boolean | undefined>; positionals: string[] },
    io: CliIo,
): Promise<number> {
    const { values, positionals } = parsed;
    const out = new Printer(io, values['quiet'] === true);

    try {
        const options = await loadOptions(io, asString(values['config']), asString(values['env-file']));
        const manager = io.createManager(options);

        return await command.run({
            io,
            out,
            manager,
            positionals,
            values,
            blob: () => manager.connection(asString(values['connection'])).container(asString(values['container'])),
        });
    } catch (error) {
        out.error(error instanceof Error ? error.message : String(error));

        if (error instanceof AzureBlobError && error.errorCode !== null) {
            io.stderr.write(`Código do Azure: ${error.errorCode}\n`);
        }

        return 1;
    }
}

function asString(value: unknown): string | undefined {
    return typeof value === 'string' && value !== '' ? value : undefined;
}

function help(version: string): string {
    const commands = Object.entries(COMMANDS)
        .map(([name, command]) => `  ${name.padEnd(10)}${command.description}`)
        .join('\n');

    return `azure-blob ${version} — Azure Blob Storage na linha de comando

Uso: azure-blob <comando> [argumentos] [opções]

Comandos:
${commands}

Opções globais:
  -c, --connection <nome>  Conexão (padrão: AZURE_BLOB_CONNECTION ou "default")
      --container <nome>   Container alvo (padrão: o da conexão)
      --config <arquivo>   JSON ou módulo JS com as opções do BlobManager
      --env-file <arquivo> Arquivo .env (padrão: ./.env, se existir)
  -q, --quiet              Suprime mensagens; dados (JSON, --stdout, URLs) continuam saindo
  -h, --help               Ajuda do comando

Sem --config, as credenciais vêm das mesmas variáveis do SDK PHP:
AZURE_STORAGE_SAS_URL, AZURE_STORAGE_CONNECTION_STRING, AZURE_STORAGE_NAME +
AZURE_STORAGE_KEY, AZURE_STORAGE_CONTAINER.`;
}

function commandHelp(name: string, command: Command): string {
    return `azure-blob ${command.usage}\n\n${command.description}.\n\nTodas as opções globais se aplicam (azure-blob --help).${name === 'delete' ? '\nSem --force, pede confirmação; fora de um terminal interativo, cancela.' : ''}`;
}
