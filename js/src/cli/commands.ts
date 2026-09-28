import { stat } from 'node:fs/promises';
import { basename as fileBasename, join, resolve } from 'node:path';
import type { BlobClient } from '../blob-client.js';
import type { BlobManager } from '../blob-manager.js';
import { AzureBlobError } from '../errors.js';
import type { BlobItem } from '../results/blob-item.js';
import { humanSize } from '../results/format.js';
import { basename } from '../support/path.js';
import type { CliIo, Printer } from './io.js';

/**
 * Os comandos do CLI — equivalentes aos `azure:*` do Artisan no SDK PHP.
 */

export type Values = Readonly<Record<string, string | boolean | undefined>>;

export interface CommandContext {
    io: CliIo;
    out: Printer;
    manager: BlobManager;
    positionals: readonly string[];
    values: Values;
    /** Cliente já apontando para `--connection` e `--container`. */
    blob(): BlobClient;
}

export type OptionSpec = Record<string, { type: 'string' | 'boolean'; short?: string; default?: string | boolean }>;

export interface Command {
    usage: string;
    description: string;
    options: OptionSpec;
    run(context: CommandContext): Promise<number>;
}

const SUCCESS = 0;
const FAILURE = 1;

function required(context: CommandContext, index: number, name: string): string {
    const value = context.positionals[index];

    if (value === undefined || value === '') {
        throw new AzureBlobError(`azure-blob: argumento "${name}" é obrigatório.`);
    }

    return value;
}

function text(values: Values, name: string): string | undefined {
    const value = values[name];

    return typeof value === 'string' && value !== '' ? value : undefined;
}

function json(value: unknown): string {
    return `${JSON.stringify(value, null, 4)}\n`;
}

function dateCell(date: Date | null): string {
    if (date === null) {
        return '—';
    }

    const pad = (value: number): string => String(value).padStart(2, '0');

    return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

const info: Command = {
    usage: 'info [--check]',
    description: 'Lista as conexões, o modo de autenticação de cada uma e valida o acesso',
    options: { check: { type: 'boolean' } },
    async run({ manager, out, values }) {
        const only = text(values, 'connection');
        const names = only === undefined ? manager.connectionNames() : [only];

        if (names.length === 0) {
            out.error('Nenhuma conexão configurada.');

            return FAILURE;
        }

        const defaultName = manager.defaultConnection();
        let failed = false;
        const rows: string[][] = [];

        for (const name of names) {
            const label = name === defaultName ? `${name} *` : name;

            try {
                const client = manager.connection(name);
                let status = 'ok';

                if (values['check'] === true) {
                    status = await client.list(null, 1).then(
                        () => 'ok',
                        (error: unknown) => (error as Error).message,
                    );
                }

                failed ||= status !== 'ok';
                rows.push([label, client.config().accountName ?? '—', client.containerName(), client.info(), status]);
            } catch (error) {
                failed = true;
                rows.push([label, '—', '—', '—', (error as Error).message]);
            }
        }

        out.table(['Conexão', 'Conta', 'Container', 'Descrição', 'Status'], rows);
        out.line('* conexão padrão', 'gray');

        return failed ? FAILURE : SUCCESS;
    },
};

const list: Command = {
    usage: 'list [prefixo] [--max=100] [--all] [--shallow] [--json]',
    description: 'Lista os blobs de um container',
    options: {
        max: { type: 'string', default: '100' },
        all: { type: 'boolean' },
        shallow: { type: 'boolean' },
        json: { type: 'boolean' },
    },
    async run(context) {
        const { out, values } = context;
        const blob = context.blob();
        const prefix = context.positionals[0] ?? '';
        const max = Number.parseInt(String(values['max']), 10) || 100;
        let items: BlobItem[] = [];
        let directories: readonly BlobItem[] = [];

        if (values['all'] === true) {
            for await (const item of blob.listAll(prefix)) {
                items.push(item);
            }
        } else if (values['shallow'] === true) {
            const page = await blob.directory(prefix, max);
            items = [...page.items];
            directories = page.directories;
        } else {
            items = [...(await blob.list(prefix, max)).items];
        }

        if (values['json'] === true) {
            out.raw(json([...directories, ...items].map((item) => item.toJSON())));

            return SUCCESS;
        }

        if (items.length === 0 && directories.length === 0) {
            out.line(`Nenhum blob encontrado${prefix === '' ? '' : ` com o prefixo "${prefix}"`}.`, 'gray');

            return SUCCESS;
        }

        out.table(['Blob', 'Tamanho', 'Tipo', 'Modificado'], [
            ...directories.map((directory) => [`${directory.name}/`, '—', 'pasta', '—']),
            ...items.map((item) => [item.name, item.humanSize(), item.contentType ?? '—', dateCell(item.lastModified)]),
        ]);

        const total = items.reduce((sum, item) => sum + item.size, 0);
        out.line(`${items.length} blob(s), ${humanSize(total)} no total — container "${blob.containerName()}".`, 'gray');

        return SUCCESS;
    },
};

const download: Command = {
    usage: 'download <blob> [destino] [--stdout]',
    description: 'Baixa um blob para o disco local ou para a saída padrão',
    options: { stdout: { type: 'boolean' } },
    async run(context) {
        const name = required(context, 0, 'blob');
        const blob = context.blob();

        if (context.values['stdout'] === true) {
            context.out.raw(await blob.get(name));

            return SUCCESS;
        }

        const target = resolve(context.io.cwd, context.positionals[1] ?? '.');
        const isDirectory = await stat(target).then(
            (info) => info.isDirectory(),
            () => false,
        );
        const destination = isDirectory ? join(target, basename(name)) : target;

        // downloadTo() usa stream: um PDF de 2 GB não passa pela memória.
        const bytes = await blob.downloadTo(name, destination);
        context.out.line(`${name} → ${destination} (${humanSize(bytes)})`, 'green');

        return SUCCESS;
    },
};

const upload: Command = {
    usage: 'upload <arquivo> [blob] [--content-type=tipo] [--no-overwrite]',
    description: 'Envia um arquivo local para um blob',
    options: { 'content-type': { type: 'string' }, 'no-overwrite': { type: 'boolean' } },
    async run(context) {
        const file = required(context, 0, 'arquivo');
        const name = context.positionals[1] ?? fileBasename(file);
        const contentType = text(context.values, 'content-type');

        const url = await context.blob().uploadFile(name, resolve(context.io.cwd, file), {
            overwrite: context.values['no-overwrite'] !== true,
            ...(contentType === undefined ? {} : { contentType }),
        });

        context.out.line(`${file} → ${name}`, 'green');
        context.out.line(url, 'gray');

        return SUCCESS;
    },
};

const exists: Command = {
    usage: 'exists <blob> [--json]',
    description: 'Verifica se um blob existe e mostra suas propriedades (sai com 1 se não existir)',
    options: { json: { type: 'boolean' } },
    async run(context) {
        const name = required(context, 0, 'blob');
        const blob = context.blob();
        const asJson = context.values['json'] === true;

        if (!(await blob.exists(name))) {
            if (asJson) {
                context.out.raw(`${JSON.stringify({ exists: false, name })}\n`);
            } else {
                context.out.error(`"${name}" não existe no container "${blob.containerName()}".`);
            }

            return FAILURE;
        }

        const properties = await blob.properties(name);

        if (asJson) {
            context.out.raw(json({ exists: true, ...properties.toJSON() }));

            return SUCCESS;
        }

        const rows: string[][] = [];

        for (const [key, value] of Object.entries(properties.toJSON())) {
            if (value === null || key === 'metadata') {
                continue;
            }

            rows.push([key, key === 'size' ? `${properties.humanSize()} (${String(value)} bytes)` : String(value)]);
        }

        for (const [key, value] of Object.entries(properties.metadata)) {
            rows.push([`meta.${key}`, value]);
        }

        context.out.table(['Propriedade', 'Valor'], rows);

        return SUCCESS;
    },
};

const remove: Command = {
    usage: 'delete <blob> [--recursive] [--force]',
    description: 'Remove um blob, ou todos sob um prefixo com --recursive (pede confirmação)',
    options: { recursive: { type: 'boolean' }, force: { type: 'boolean', short: 'f' } },
    async run(context) {
        const name = required(context, 0, 'blob');
        const blob = context.blob();
        const recursive = context.values['recursive'] === true;
        const question = recursive
            ? `Remover TODOS os blobs sob "${name}" no container "${blob.containerName()}"?`
            : `Remover "${name}" do container "${blob.containerName()}"?`;

        // A remoção é irreversível sem soft delete habilitado na conta, então a
        // confirmação é o padrão e --force é a exceção.
        if (context.values['force'] !== true && !(await context.io.confirm(question))) {
            context.out.line('Cancelado.', 'gray');

            return SUCCESS;
        }

        if (recursive) {
            const removed = await blob.deleteDirectory(name);
            context.out.line(`${removed} blob(s) removido(s) sob "${name}".`, 'green');
        } else if (await blob.delete(name)) {
            context.out.line(`"${name}" removido.`, 'green');
        } else {
            context.out.line(`"${name}" já não existia.`, 'gray');
        }

        return SUCCESS;
    },
};

const copy: Command = {
    usage: 'copy <origem> <destino> [--from=container] [--to=container] [--move] [--wait]',
    description: 'Copia (ou move) um blob, possivelmente entre containers',
    options: { from: { type: 'string' }, to: { type: 'string' }, move: { type: 'boolean' }, wait: { type: 'boolean' } },
    async run(context) {
        const source = required(context, 0, 'origem');
        const destination = required(context, 1, 'destino');
        const moving = context.values['move'] === true;
        const options = {
            sourceContainer: text(context.values, 'from') ?? null,
            destinationContainer: text(context.values, 'to') ?? null,
            wait: context.values['wait'] === true,
        };

        const blob = context.blob();
        const url = moving ? await blob.move(source, destination, options) : await blob.copy(source, destination, options);

        context.out.line(`${moving ? 'Movido' : 'Copiado'} ${source} → ${destination}`, 'green');
        context.out.line(url, 'gray');

        return SUCCESS;
    },
};

const sas: Command = {
    usage: 'sas [blob] [--hours=1] [--permissions=r]',
    description: 'Gera uma URL com SAS token para um blob, ou para o container sem o argumento',
    options: { hours: { type: 'string', default: '1' }, permissions: { type: 'string', default: 'r' } },
    async run(context) {
        const blob = context.blob();
        const name = context.positionals[0];
        const hours = Math.max(1, Number.parseInt(String(context.values['hours']), 10) || 1);
        const permissions = String(context.values['permissions']);

        const url =
            name !== undefined && name !== ''
                ? blob.temporaryUrl(name, hours, permissions)
                : blob.temporaryContainerUrl(hours, permissions === 'r' ? 'rl' : permissions);

        // Com sasUrl configurada o token do container é reaproveitado: a
        // validade é a dele, não a pedida aqui.
        if (blob.config().usesSasToken()) {
            context.io.stderr.write(
                'Conexão em modo SAS URL: o token do container foi reaproveitado, --hours e --permissions não se aplicam.\n',
            );
        }

        context.out.raw(`${url}\n`);

        return SUCCESS;
    },
};

export const COMMANDS: Readonly<Record<string, Command>> = {
    info,
    list,
    download,
    upload,
    exists,
    delete: remove,
    copy,
    sas,
};
