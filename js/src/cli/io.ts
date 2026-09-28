import { createInterface } from 'node:readline/promises';
import type { BlobManager, ManagerOptions } from '../blob-manager.js';

/**
 * Entrada e saída do CLI, injetáveis para os testes.
 */

export interface Output {
    write(chunk: string | Uint8Array): unknown;
}

export interface CliIo {
    stdout: Output;
    stderr: Output;
    env: Readonly<Record<string, string | undefined>>;
    cwd: string;
    /** Cores ANSI na saída (terminal interativo e sem NO_COLOR). */
    color: boolean;
    /** Pergunta sim/não; em ambiente não interativo deve devolver `false`. */
    confirm(question: string): Promise<boolean>;
    /** Fábrica do gerenciador — os testes a trocam para injetar um `fetch` falso. */
    createManager(options: ManagerOptions): BlobManager;
}

export function processIo(createManager: CliIo['createManager']): CliIo {
    const interactive = process.stdin.isTTY === true && process.stdout.isTTY === true;

    return {
        stdout: process.stdout,
        stderr: process.stderr,
        env: process.env,
        cwd: process.cwd(),
        color: process.stdout.isTTY === true && process.env['NO_COLOR'] === undefined,
        createManager,
        async confirm(question: string): Promise<boolean> {
            if (!interactive) {
                return false;
            }

            const prompt = createInterface({ input: process.stdin, output: process.stdout });

            try {
                const answer = await prompt.question(`${question} [s/N] `);

                return ['s', 'sim', 'y', 'yes'].includes(answer.trim().toLowerCase());
            } finally {
                prompt.close();
            }
        },
    };
}

const STYLES = { red: [31, 39], green: [32, 39], yellow: [33, 39], gray: [90, 39], bold: [1, 22] } as const;

export type Style = keyof typeof STYLES;

/** Escreve uma linha, colorida quando o terminal permite. */
export class Printer {
    constructor(
        private readonly io: CliIo,
        private readonly quiet: boolean,
    ) {}

    line(text = '', style?: Style): void {
        if (!this.quiet) {
            this.io.stdout.write(`${this.paint(text, style)}\n`);
        }
    }

    /** Dado bruto (download --stdout, JSON): não é silenciado por --quiet. */
    raw(chunk: string | Uint8Array): void {
        this.io.stdout.write(chunk);
    }

    error(text: string): void {
        this.io.stderr.write(`${this.paint(text, 'red')}\n`);
    }

    table(headers: readonly string[], rows: ReadonlyArray<readonly string[]>): void {
        const widths = headers.map((header, column) =>
            Math.max(header.length, ...rows.map((row) => (row[column] ?? '').length)),
        );
        const separator = `+${widths.map((width) => '-'.repeat(width + 2)).join('+')}+`;
        const format = (cells: readonly string[]): string =>
            `| ${cells.map((cell, column) => cell.padEnd(widths[column] ?? 0)).join(' | ')} |`;

        this.line(separator);
        this.line(format(headers), 'bold');
        this.line(separator);
        rows.forEach((row) => this.line(format(row)));
        this.line(separator);
    }

    private paint(text: string, style: Style | undefined): string {
        if (style === undefined || !this.io.color) {
            return text;
        }

        const [open, close] = STYLES[style];

        return `\u001b[${open}m${text}\u001b[${close}m`;
    }
}
