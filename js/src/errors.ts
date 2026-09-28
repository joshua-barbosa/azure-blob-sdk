/**
 * Erros do SDK.
 *
 * Todos herdam de AzureBlobError e carregam um contexto estruturado (status
 * HTTP, código de erro do Azure, nome do blob) para que quem captura possa
 * decidir sem reparsear a mensagem. As chaves do contexto seguem o mesmo
 * formato do SDK PHP, o que mantém os logs das duas linguagens comparáveis.
 */

export type ErrorContext = Readonly<Record<string, unknown>>;

export interface AzureBlobErrorOptions {
    cause?: unknown;
    context?: Record<string, unknown>;
}

export class AzureBlobError extends Error {
    override readonly name: string = 'AzureBlobError';

    readonly context: ErrorContext;

    constructor(message: string, options: AzureBlobErrorOptions = {}) {
        super(message, options.cause === undefined ? undefined : { cause: options.cause });
        this.context = Object.freeze({ ...(options.context ?? {}) });
    }

    /** Status HTTP da resposta que originou a falha, quando houver. */
    get status(): number | null {
        const status = this.context['status'];

        return typeof status === 'number' ? status : null;
    }

    /** Código de erro devolvido pelo Azure (ex.: `BlobNotFound`), quando houver. */
    get errorCode(): string | null {
        const code = this.context['error_code'];

        return typeof code === 'string' && code !== '' ? code : null;
    }
}

/** O Azure respondeu 404 para um blob ou container. */
export class BlobNotFoundError extends AzureBlobError {
    override readonly name: string = 'BlobNotFoundError';

    static make(blob: string, container: string, errorCode: string | null = null): BlobNotFoundError {
        // Operações de container (list) chegam sem blob: o que falta é o container.
        if (blob === '') {
            return new BlobNotFoundError(`azure-blob: container "${container}" não encontrado.`, {
                context: { blob, container, status: 404, error_code: errorCode ?? 'ContainerNotFound' },
            });
        }

        return new BlobNotFoundError(`azure-blob: blob "${blob}" não encontrado no container "${container}".`, {
            context: { blob, container, status: 404, error_code: errorCode ?? 'BlobNotFound' },
        });
    }
}

/**
 * Um download em memória excederia o limite configurado.
 *
 * Existe para proteger o processo: use `stream()` ou `downloadTo()` para
 * arquivos grandes em vez de aumentar o limite indefinidamente.
 */
export class BlobTooLargeError extends AzureBlobError {
    override readonly name: string = 'BlobTooLargeError';

    static make(blob: string, size: number, max: number): BlobTooLargeError {
        return new BlobTooLargeError(
            `azure-blob: blob "${blob}" tem ${size} bytes e excede o limite de ${max} bytes para download ` +
                'em memória. Use stream() ou downloadTo(), ou ajuste "maxDownloadSize".',
            { context: { blob, size, max_download_size: max } },
        );
    }
}

/**
 * A conexão não tem credenciais suficientes ou aponta para um container
 * indefinido. Indica erro de configuração, não falha de comunicação.
 */
export class ConfigurationError extends AzureBlobError {
    override readonly name: string = 'ConfigurationError';

    static missingCredentials(connection: string): ConfigurationError {
        return new ConfigurationError(
            `azure-blob [${connection}]: nenhuma credencial configurada. Defina um destes modos de ` +
                'autenticação: "sasUrl" (recomendado), "connectionString", ou "name" + "key".',
        );
    }

    static missingContainer(connection: string): ConfigurationError {
        return new ConfigurationError(
            `azure-blob [${connection}]: container não configurado. Defina "container" na conexão ` +
                'ou inclua o container na SAS URL.',
        );
    }

    static unknownConnection(connection: string): ConfigurationError {
        return new ConfigurationError(`azure-blob: conexão "${connection}" não está entre as conexões configuradas.`);
    }

    static invalidSasUrl(connection: string): ConfigurationError {
        return new ConfigurationError(
            `azure-blob [${connection}]: "sasUrl" inválida. Esperado ` +
                '"https://<conta>.blob.core.windows.net/<container>?<sas_token>".',
        );
    }

    static signingUnavailable(connection: string): ConfigurationError {
        return new ConfigurationError(
            `azure-blob [${connection}]: esta operação exige a chave da conta. Configure "name" + "key" ` +
                'ou uma connection string com AccountName/AccountKey.',
        );
    }
}

/**
 * Tentativa de escrita numa conexão marcada como somente leitura.
 *
 * A trava é local ao SDK: serve para impedir que um container de produção seja
 * alterado por engano, não substitui as permissões do próprio SAS token.
 */
export class ReadOnlyError extends AzureBlobError {
    override readonly name: string = 'ReadOnlyError';

    static for(connection: string, operation: string): ReadOnlyError {
        return new ReadOnlyError(
            `azure-blob [${connection}]: operação "${operation}" bloqueada — a conexão está em modo somente leitura.`,
            { context: { connection, operation } },
        );
    }
}
