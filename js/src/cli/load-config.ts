import { existsSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { isAbsolute, join } from 'node:path';
import { pathToFileURL } from 'node:url';
import type { ManagerOptions } from '../blob-manager.js';
import { type Env, optionsFromEnv } from '../env.js';
import { ConfigurationError } from '../errors.js';

/**
 * Resolve a configuração do CLI.
 *
 * `--config` aponta para um JSON ou módulo JS que exporta as opções do
 * gerenciador. Sem ele, valem as variáveis de ambiente — completadas por um
 * `.env` (o do diretório atual, ou o de `--env-file`), como no Laravel. O
 * ambiente real tem precedência sobre o arquivo.
 */
export async function loadOptions(
    io: { env: Env; cwd: string },
    configFile: string | undefined,
    envFile: string | undefined,
): Promise<ManagerOptions> {
    if (configFile !== undefined) {
        return readConfigFile(resolve(io.cwd, configFile));
    }

    const path = envFile === undefined ? join(io.cwd, '.env') : resolve(io.cwd, envFile);

    if (envFile !== undefined && !existsSync(path)) {
        throw new ConfigurationError(`azure-blob: arquivo de ambiente "${envFile}" não encontrado.`);
    }

    const fromFile = existsSync(path) ? parseDotenv(await readFile(path, 'utf8')) : {};

    return optionsFromEnv({ ...fromFile, ...definedOnly(io.env) });
}

async function readConfigFile(path: string): Promise<ManagerOptions> {
    if (!existsSync(path)) {
        throw new ConfigurationError(`azure-blob: arquivo de configuração "${path}" não encontrado.`);
    }

    try {
        if (path.endsWith('.json')) {
            return JSON.parse(await readFile(path, 'utf8')) as ManagerOptions;
        }

        const module = (await import(pathToFileURL(path).href)) as { default?: ManagerOptions } & ManagerOptions;

        return module.default ?? module;
    } catch (error) {
        throw new ConfigurationError(`azure-blob: não foi possível ler "${path}": ${(error as Error).message}.`, {
            cause: error,
        });
    }
}

/** Subconjunto do formato .env: `CHAVE=valor`, aspas, comentários e `export`. */
export function parseDotenv(contents: string): Record<string, string> {
    const values: Record<string, string> = {};

    for (const rawLine of contents.split(/\r?\n/)) {
        const line = rawLine.trim().replace(/^export\s+/, '');
        const separator = line.indexOf('=');

        if (line === '' || line.startsWith('#') || separator <= 0) {
            continue;
        }

        const key = line.slice(0, separator).trim();
        let value = line.slice(separator + 1).trim();
        const quote = value[0];

        if ((quote === '"' || quote === "'") && value.endsWith(quote) && value.length >= 2) {
            value = value.slice(1, -1);
        } else {
            value = value.replace(/\s+#.*$/, '');
        }

        values[key] = value;
    }

    return values;
}

function resolve(cwd: string, path: string): string {
    return isAbsolute(path) ? path : join(cwd, path);
}

function definedOnly(env: Env): Record<string, string> {
    return Object.fromEntries(Object.entries(env).filter((entry): entry is [string, string] => entry[1] !== undefined));
}
