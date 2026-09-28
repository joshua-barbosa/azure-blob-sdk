import { Readable } from 'node:stream';
import type { ReadableStream as WebReadableStream } from 'node:stream/web';
import type { Config, FetchFunction } from '../config.js';
import { AzureBlobError, BlobNotFoundError, ConfigurationError } from '../errors.js';
import { encodeRaw, normalize, rawEncode } from './path.js';
import { scrub } from './redactor.js';
import { SasBuilder } from './sas-builder.js';
import { type HeaderMap, SharedKeySigner, httpDate } from './shared-key-signer.js';
import { parseError } from './xml.js';

/**
 * Executa as chamadas à REST API do Blob Storage.
 *
 * Concentra o que é comum a toda requisição — montagem da URL, autenticação
 * (SAS token na query ou assinatura Shared Key nos cabeçalhos), timeout e
 * tradução de erros — para que BlobClient trate só da semântica de cada
 * operação.
 */

export type Query = Record<string, string | number>;

export interface RequestOptions {
    query?: Query;
    headers?: HeaderMap;
    body?: Uint8Array | null;
    /** Status extras aceitos sem lançar erro (ex.: 404 em `exists`). */
    allow?: readonly number[];
}

export interface AzureResponse {
    status: number;
    headers: Headers;
    body: Buffer;
}

export interface AzureStreamResponse {
    status: number;
    headers: Headers;
    stream: Readable;
}

export class RestClient {
    private signer: SharedKeySigner | null = null;

    constructor(readonly config: Config) {}

    /** Cópia com outra configuração (ex.: outro container). */
    withConfig(config: Config): RestClient {
        return config === this.config ? this : new RestClient(config);
    }

    /**
     * Requisição com corpo lido por inteiro. Respostas de erro viram exceção,
     * exceto os status listados em `allow`.
     *
     * @param path Caminho relativo à conta (ex.: `container/pasta/a.txt`), sem encoding.
     */
    async request(method: string, path: string, options: RequestOptions = {}): Promise<AzureResponse> {
        const { response, cancel, aborted } = await this.send(method, path, options);

        try {
            const body = Buffer.from(await response.arrayBuffer());

            if (response.ok || options.allow?.includes(response.status)) {
                return { status: response.status, headers: response.headers, body };
            }

            throw this.exception(response.status, response.headers, body.toString('utf8'), method, path);
        } catch (error) {
            throw this.wrap(error, method, path, aborted());
        } finally {
            cancel();
        }
    }

    /**
     * Requisição cujo corpo é devolvido como stream, sem passar pela memória.
     *
     * O timeout vale até a resposta começar a chegar: um download de 2 GB
     * legítimo não deve ser cortado no meio por ele.
     */
    async stream(method: string, path: string, options: RequestOptions = {}): Promise<AzureStreamResponse> {
        const { response, cancel } = await this.send(method, path, options);

        if (!response.ok) {
            // O corpo do erro ainda corre sob o timeout.
            const body = await response.text().catch(() => '');
            cancel();

            throw this.exception(response.status, response.headers, body, method, path);
        }

        cancel();

        const stream =
            response.body === null
                ? Readable.from([])
                : Readable.fromWeb(response.body as unknown as WebReadableStream<Uint8Array>);

        return { status: response.status, headers: response.headers, stream };
    }

    /**
     * Monta a URL absoluta, anexando o SAS token quando ele é a credencial.
     *
     * O token é concatenado cru: reencodar a assinatura (`sig`) invalidaria o
     * SAS.
     */
    url(path: string, query: Query = {}): string {
        // O caminho chega pronto do BlobClient (normalizado ou, para nomes
        // vindos da listagem, exatamente como o Azure os devolveu).
        const url = `${this.config.accountUrl.replace(/\/+$/, '')}/${encodeRaw(path)}`;
        const parts: string[] = [];

        const encoded = Object.entries(query)
            .map(([name, value]) => `${rawEncode(name)}=${rawEncode(String(value))}`)
            .join('&');

        if (encoded !== '') {
            parts.push(encoded);
        }

        if (this.config.usesSasToken() && this.config.sasToken !== '') {
            parts.push(this.config.sasToken);
        }

        return parts.length === 0 ? url : `${url}?${parts.join('&')}`;
    }

    /** Gerador de SAS da conexão; exige a chave da conta. */
    sas(): SasBuilder {
        if (!this.config.canSign()) {
            throw ConfigurationError.signingUnavailable(this.config.connection);
        }

        return new SasBuilder(this.config.accountName as string, this.config.accountKey as string, this.config.apiVersion);
    }

    private async send(
        method: string,
        path: string,
        options: RequestOptions,
    ): Promise<{ response: Response; cancel: () => void; aborted: () => boolean }> {
        const verb = method.toUpperCase();
        const url = this.url(path, options.query);
        const headers = this.authenticate(verb, url, options.headers ?? {}, options.body ?? null);

        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), this.config.timeout * 1000);
        const fetcher: FetchFunction = this.config.fetch ?? fetch;

        const init: Omit<RequestInit, 'dispatcher'> & { dispatcher?: unknown } = {
            method: verb,
            headers,
            signal: controller.signal,
            redirect: 'manual',
        };

        if (options.body !== null && options.body !== undefined) {
            init.body = options.body;
        }

        if (this.config.dispatcher !== undefined) {
            init.dispatcher = this.config.dispatcher;
        }

        try {
            return {
                response: await fetcher(url, init as RequestInit),
                cancel: () => clearTimeout(timer),
                aborted: () => controller.signal.aborted,
            };
        } catch (error) {
            clearTimeout(timer);

            const reason = controller.signal.aborted
                ? `tempo limite de ${this.config.timeout}s esgotado`
                : describeCause(error);

            throw new AzureBlobError(`azure-blob: falha de comunicação com o Azure (${reason}).`, {
                cause: error,
                context: scrub({ connection: this.config.connection, method: verb, url }),
            });
        }
    }

    /** Aplica o modo de autenticação da conexão aos cabeçalhos. */
    private authenticate(method: string, url: string, headers: HeaderMap, body: Uint8Array | null): HeaderMap {
        const complete: HeaderMap = {
            'x-ms-version': this.config.apiVersion,
            'x-ms-date': httpDate(),
            ...headers,
        };

        if (body !== null && !hasHeader(complete, 'content-length')) {
            complete['Content-Length'] = String(body.byteLength);
        }

        // No modo SAS a credencial já viaja na query string montada por url().
        if (this.config.usesSasToken()) {
            return complete;
        }

        return this.signerFor().sign(method, url, complete, this.config.apiVersion);
    }

    private signerFor(): SharedKeySigner {
        if (this.signer !== null) {
            return this.signer;
        }

        if (!this.config.canSign()) {
            throw ConfigurationError.signingUnavailable(this.config.connection);
        }

        this.signer = new SharedKeySigner(this.config.accountName as string, this.config.accountKey as string);

        return this.signer;
    }

    /** Traduz uma resposta de erro do Azure em exceção do SDK. */
    private exception(status: number, headers: Headers, body: string, method: string, path: string): AzureBlobError {
        // O corpo de erro do Azure é XML; num HEAD ou num stream ele pode estar
        // vazio, e aí o cabeçalho x-ms-error-code é a única pista.
        const error = parseError(body);
        const code = error.code ?? (headers.get('x-ms-error-code') || null);

        // O caminho é "container/blob", mas operações de container (list) vêm
        // só com o container.
        const separator = path.indexOf('/');
        const blob = separator === -1 ? '' : normalize(path.slice(separator + 1));

        this.config.logger?.warn(
            'azure-blob: requisição rejeitada pelo Azure.',
            scrub({
                connection: this.config.connection,
                method: method.toUpperCase(),
                path,
                status,
                error_code: code,
                error_message: error.message,
            }),
        );

        if (status === 404) {
            return BlobNotFoundError.make(blob, this.config.container, code);
        }

        return new AzureBlobError(
            `azure-blob: ${method.toUpperCase()} ${path} falhou com HTTP ${status}${code === null ? '' : ` (${code})`}.`,
            {
                context: {
                    connection: this.config.connection,
                    container: this.config.container,
                    blob,
                    status,
                    error_code: code,
                    error_message: error.message,
                },
            },
        );
    }

    /** Falhas na leitura do corpo (ex.: timeout no meio) também viram AzureBlobError. */
    private wrap(error: unknown, method: string, path: string, timedOut: boolean): AzureBlobError {
        if (error instanceof AzureBlobError) {
            return error;
        }

        const reason = timedOut ? `tempo limite de ${this.config.timeout}s esgotado` : describeCause(error);

        return new AzureBlobError(`azure-blob: falha de comunicação com o Azure (${reason}).`, {
            cause: error,
            context: { connection: this.config.connection, method: method.toUpperCase(), path },
        });
    }
}

function hasHeader(headers: HeaderMap, name: string): boolean {
    return Object.keys(headers).some((key) => key.toLowerCase() === name);
}

/** O `fetch` nativo embrulha o erro real (ECONNREFUSED…) em `cause`. */
function describeCause(error: unknown): string {
    if (!(error instanceof Error)) {
        return String(error);
    }

    const cause = error.cause instanceof Error ? error.cause.message : '';

    return cause !== '' && cause !== error.message ? `${error.message}: ${cause}` : error.message;
}
