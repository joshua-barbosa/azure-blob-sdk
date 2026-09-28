import { access, constants as fsConstants, mkdir, open, rename, stat, unlink } from 'node:fs/promises';
import { dirname } from 'node:path';
import type { Readable } from 'node:stream';
import { pipeline } from 'node:stream/promises';
import type { Config } from './config.js';
import { MAX_PAGE } from './constants.js';
import { AzureBlobError, BlobTooLargeError, ReadOnlyError } from './errors.js';
import { BlobContent } from './results/blob-content.js';
import type { BlobItem } from './results/blob-item.js';
import { BlobList } from './results/blob-list.js';
import { BlobProperties } from './results/blob-properties.js';
import { DEFAULT_MIME_TYPE, guessMimeType } from './support/mime-type.js';
import { assertSafeName, directoryPrefix, normalize } from './support/path.js';
import { RestClient } from './support/rest-client.js';
import { type Expiry, type SasOptions, normalizePermissions } from './support/sas-builder.js';
import { type UploadContents, type UploadOptions, Uploader, metadataHeaders } from './support/uploader.js';

export interface ListOptions {
    marker?: string | null;
    delimiter?: string | null;
    /** Ex.: `metadata`, `snapshots`. */
    include?: string | null;
}

export interface DownloadOptions {
    /** Sobrescreve o `maxDownloadSize` da conexão só nesta chamada. */
    maxSize?: number;
}

export interface CopyOptions {
    sourceContainer?: string | null;
    destinationContainer?: string | null;
    /** Aguarda cópias assíncronas (entre containers/contas) terminarem. */
    wait?: boolean;
    /** Segundos de espera quando `wait` é true. */
    timeout?: number;
}

/**
 * Cliente de uma conexão do Azure Blob Storage.
 *
 * Cada instância aponta para um container; `container()` devolve uma cópia
 * apontando para outro, sem alterar a original.
 *
 *     const blob = manager.connection('apostilas');
 *
 *     await blob.list('2024/');
 *     await (await blob.download('2024/apostila.pdf')).saveTo('/tmp/a.pdf');
 *     await blob.upload('2024/nova.pdf', conteudo);
 *     await blob.container('backup').upload('copia.pdf', conteudo);
 */
export class BlobClient {
    /** Página máxima aceita pelo Azure em `List Blobs`. */
    static readonly MAX_PAGE = MAX_PAGE;

    private readonly rest: RestClient;
    private readonly uploader: Uploader;

    constructor(config: Config | RestClient) {
        this.rest = config instanceof RestClient ? config : new RestClient(config);
        this.uploader = new Uploader(this.rest);
    }

    config(): Config {
        return this.rest.config;
    }

    /** Nome do container atual. */
    containerName(): string {
        return this.rest.config.container;
    }

    /** Cópia apontando para outro container. `null`/vazio devolve a mesma instância. */
    container(container: string | null | undefined): BlobClient {
        const config = this.rest.config.withContainer(container);

        return config === this.rest.config ? this : new BlobClient(this.rest.withConfig(config));
    }

    /** Cópia com a trava de escrita ligada, para trechos que só devem ler. */
    readOnly(): BlobClient {
        const config = this.rest.config.readOnly();

        return config === this.rest.config ? this : new BlobClient(this.rest.withConfig(config));
    }

    isReadOnly(): boolean {
        return this.rest.config.readonly;
    }

    /** Descrição legível da conexão (conta, container, modo de auth). */
    info(): string {
        return this.rest.config.describe();
    }

    // ========================================================================
    // Leitura
    // ========================================================================

    /** Lista blobs do container (uma página). */
    async list(prefix: string | null = null, maxResults = 100, options: ListOptions = {}): Promise<BlobList> {
        const query: Record<string, string> = {
            restype: 'container',
            comp: 'list',
            maxresults: String(Math.max(1, Math.min(Math.trunc(maxResults) || 1, MAX_PAGE))),
        };

        if (prefix !== null && normalize(prefix) !== '') {
            query['prefix'] = normalize(prefix);
        }

        for (const option of ['marker', 'delimiter', 'include'] as const) {
            const value = options[option];

            if (typeof value === 'string' && value !== '') {
                query[option] = value;
            }
        }

        const response = await this.rest.request('GET', this.containerName(), { query });

        return BlobList.fromXml(response.body.toString('utf8'), this.rest.config.containerUrl(), this.containerName());
    }

    /**
     * Percorre todas as páginas, seguindo o `NextMarker`.
     *
     * Um gerador assíncrono: listar 100.000 blobs não carrega todos na memória.
     */
    async *listAll(prefix: string | null = null, options: Omit<ListOptions, 'marker'> = {}): AsyncGenerator<BlobItem> {
        let marker: string | null = null;

        do {
            const page: BlobList = await this.list(prefix, MAX_PAGE, { ...options, marker });

            yield* page.items;

            marker = page.nextMarker;
        } while (marker !== null);
    }

    /**
     * Listagem rasa de um "diretório": usa delimitador, então subpastas voltam
     * em `directories` em vez de despejar a árvore inteira.
     */
    directory(path = '', maxResults = MAX_PAGE): Promise<BlobList> {
        return this.list(directoryPrefix(path), maxResults, { delimiter: '/' });
    }

    /** Nomes dos blobs sob o prefixo, percorrendo todas as páginas. `max` limita o total. */
    async listNames(prefix: string | null = null, max: number | null = null): Promise<string[]> {
        const names: string[] = [];

        for await (const item of this.listAll(prefix)) {
            if (max !== null && names.length >= max) {
                break;
            }

            names.push(item.name);
        }

        return names;
    }

    /** Arquivos de uma "pasta". Sem `recursive`, só os do nível atual. */
    async files(path = '', recursive = false): Promise<string[]> {
        if (recursive) {
            return this.listNames(directoryPrefix(path));
        }

        const names: string[] = [];

        for await (const page of this.directoryPages(path)) {
            names.push(...page.names());
        }

        return names;
    }

    /** Subpastas imediatas de uma "pasta", sem barra final. */
    async directories(path = ''): Promise<string[]> {
        const names: string[] = [];

        for await (const page of this.directoryPages(path)) {
            names.push(...page.directories.map((directory) => directory.name));
        }

        return names;
    }

    /** Baixa o conteúdo do blob para a memória, respeitando `maxDownloadSize`. */
    async download(blob: string, options: DownloadOptions = {}): Promise<BlobContent> {
        const name = normalize(blob);
        const properties = await this.properties(name);
        const max = options.maxSize ?? this.rest.config.maxDownloadSize;

        if (max > 0 && properties.size > max) {
            throw BlobTooLargeError.make(name, properties.size, max);
        }

        const response = await this.rest.request('GET', this.path(name));

        return new BlobContent({
            contents: response.body,
            name,
            contentType: properties.contentType,
            // Os bytes entregues: com Content-Encoding gzip/br/deflate o fetch
            // nativo descomprime, e o tamanho difere do armazenado.
            size: response.body.length,
            properties,
        });
    }

    /**
     * Baixa e decodifica um blob JSON. O limite de tamanho não se aplica: o
     * JSON seria decodificado na memória de qualquer forma.
     */
    async downloadJson<T = unknown>(blob: string): Promise<T> {
        const name = normalize(blob);
        const response = await this.rest.request('GET', this.path(name));

        return new BlobContent({
            contents: response.body,
            name,
            contentType: response.headers.get('content-type'),
        }).json<T>();
    }

    /** Conteúdo do blob decodificado como UTF-8, sem checagem de tamanho. */
    async downloadText(blob: string): Promise<string> {
        return (await this.get(blob)).toString('utf8');
    }

    /** Bytes crus do blob, sem checagem de tamanho. */
    async get(blob: string): Promise<Buffer> {
        return (await this.rest.request('GET', this.path(blob))).body;
    }

    /** Stream de leitura do blob, sem carregar tudo na memória. */
    async stream(blob: string): Promise<Readable> {
        return (await this.rest.stream('GET', this.path(blob))).stream;
    }

    /**
     * Baixa direto para um arquivo local, por stream. Devolve os bytes gravados.
     *
     * Grava num arquivo temporário ao lado do destino e só renomeia no fim:
     * um 404 ou uma queda no meio não destroem uma cópia anterior do arquivo.
     */
    async downloadTo(blob: string, path: string): Promise<number> {
        const partial = `${path}.${process.pid}.${Date.now().toString(36)}.part`;

        // Cria a pasta de destino quando falta, como o azure_download_blob_to_file original.
        await mkdir(dirname(path), { recursive: true }).catch((error: unknown) => {
            throw new AzureBlobError(`azure-blob: não foi possível criar a pasta "${dirname(path)}".`, {
                cause: error,
                context: { blob, path },
            });
        });

        // O arquivo é aberto antes do download para falhar cedo, sem gastar
        // uma requisição quando o destino não é gravável.
        const handle = await open(partial, 'wx').catch((error: unknown) => {
            throw new AzureBlobError(`azure-blob: não foi possível abrir "${path}" para escrita.`, {
                cause: error,
                context: { blob, path },
            });
        });

        let written = 0;

        try {
            const source = await this.stream(blob).catch(async (error: unknown) => {
                await handle.close();
                throw error;
            });

            source.on('data', (chunk: Buffer) => {
                written += chunk.length;
            });

            // O stream de escrita fecha o arquivo ao terminar ou falhar.
            await pipeline(source, handle.createWriteStream());
            await rename(partial, path);
        } catch (error) {
            await unlink(partial).catch(() => undefined);
            throw error;
        }

        return written;
    }

    /** Propriedades e metadados do blob. */
    async properties(blob: string): Promise<BlobProperties> {
        const name = normalize(blob);
        const response = await this.rest.request('HEAD', this.path(name));

        return BlobProperties.fromHeaders(response.headers, name, this.containerName(), this.url(name));
    }

    async exists(blob: string): Promise<boolean> {
        const response = await this.rest.request('HEAD', this.path(blob), { allow: [404] });

        return response.status !== 404;
    }

    async missing(blob: string): Promise<boolean> {
        return !(await this.exists(blob));
    }

    async size(blob: string): Promise<number> {
        return (await this.properties(blob)).size;
    }

    async lastModified(blob: string): Promise<Date | null> {
        return (await this.properties(blob)).lastModified;
    }

    async mimeType(blob: string): Promise<string | null> {
        return (await this.properties(blob)).contentType;
    }

    /** URL pública do blob. Só abre sem token se o container for público. */
    url(blob: string): string {
        return this.rest.config.blobUrl(blob);
    }

    /**
     * URL assinada, válida por tempo limitado.
     *
     * Com `sasUrl` configurada o token do container é reaproveitado e
     * `expiry`/`permissions` são ignorados — expiração e permissões já vêm
     * fixadas no token. Nos modos com chave da conta um SAS novo é assinado.
     *
     * @param expiry Instante de expiração, ou horas a partir de agora.
     * @param permissions r=read, a=add, c=create, w=write, d=delete, l=list.
     */
    temporaryUrl(blob: string, expiry: Expiry = 1, permissions = 'r', options: SasOptions = {}): string {
        const name = normalize(blob);
        const config = this.rest.config;

        if (config.usesSasToken()) {
            return `${this.url(name)}?${config.sasToken}`;
        }

        return `${this.url(name)}?${this.rest.sas().forBlob(this.containerName(), name, expiry, permissions, options)}`;
    }

    /** Alias de `temporaryUrl()`, com o nome usado pela ferramenta MCP. */
    sasUrl(blob: string, expiry: Expiry = 1, permissions = 'r'): string {
        return this.temporaryUrl(blob, expiry, permissions);
    }

    /** URL assinada do container inteiro (útil para listagem delegada). */
    temporaryContainerUrl(expiry: Expiry = 1, permissions = 'rl'): string {
        const config = this.rest.config;

        if (config.usesSasToken()) {
            return `${config.containerUrl()}?${config.sasToken}`;
        }

        return `${config.containerUrl()}?${this.rest.sas().forContainer(this.containerName(), expiry, permissions)}`;
    }

    // ========================================================================
    // Container
    // ========================================================================

    /** True quando o container da conexão existe. */
    async containerExists(): Promise<boolean> {
        // Um SAS de container não autoriza Get Container Properties (403), mas
        // autoriza listar: uma listagem de 1 item responde 404 sem o container.
        const response = this.rest.config.usesSasToken()
            ? await this.rest.request('GET', this.containerName(), {
                  query: { restype: 'container', comp: 'list', maxresults: 1 },
                  allow: [404],
              })
            : await this.rest.request('HEAD', this.containerName(), { query: { restype: 'container' }, allow: [404] });

        return response.status !== 404;
    }

    /**
     * Cria o container quando ele não existe. `true` se criou, `false` se já
     * existia.
     *
     * Criar container exige a chave da conta ou um SAS de conta: um SAS de
     * container (o modo SAS URL típico) não tem essa permissão.
     */
    async ensureContainer(): Promise<boolean> {
        this.guardWrites('ensureContainer');

        const response = await this.rest.request('PUT', this.containerName(), {
            query: { restype: 'container' },
            allow: [409],
        });

        if (response.status !== 409) {
            return true;
        }

        const code = response.headers.get('x-ms-error-code');

        // 409 também chega para ContainerBeingDeleted: aí o container não
        // existe e não vai existir tão cedo, o que não é "já existia".
        if (code !== null && code !== '' && code !== 'ContainerAlreadyExists') {
            throw new AzureBlobError(`azure-blob: não foi possível criar o container "${this.containerName()}" (${code}).`, {
                context: { container: this.containerName(), status: 409, error_code: code },
            });
        }

        return false;
    }

    // ========================================================================
    // Escrita
    // ========================================================================

    /** Envia conteúdo para um blob. Devolve a URL do blob. */
    async upload(blob: string, contents: UploadContents, options: UploadOptions = {}): Promise<string> {
        this.guardWrites('upload');

        return this.uploader.upload(this.path(blob), contents, options);
    }

    /** Serializa e envia dados como JSON. */
    async uploadJson(blob: string, data: unknown, options: UploadOptions = {}): Promise<string> {
        let json: string | undefined;

        try {
            json = JSON.stringify(data, null, 4);
        } catch (error) {
            throw new AzureBlobError(
                `azure-blob: não foi possível serializar os dados de "${blob}": ${(error as Error).message}.`,
                { cause: error, context: { blob } },
            );
        }

        if (json === undefined) {
            throw new AzureBlobError(`azure-blob: não foi possível serializar os dados de "${blob}".`, {
                context: { blob },
            });
        }

        return this.upload(blob, json, { contentType: 'application/json', ...options });
    }

    /** Envia um arquivo local, em blocos quando necessário. */
    async uploadFile(blob: string, path: string, options: UploadOptions = {}): Promise<string> {
        const readable = await stat(path)
            .then((info) => info.isFile())
            .then(async (isFile) => isFile && (await access(path, fsConstants.R_OK).then(() => true)))
            .catch(() => false);

        if (!readable) {
            throw new AzureBlobError(`azure-blob: arquivo "${path}" não existe ou não pode ser lido.`, {
                context: { blob, path },
            });
        }

        const stream = (await open(path, 'r')).createReadStream();

        try {
            // O tipo vem do nome do blob; o do arquivo local só entra quando o
            // blob não tem extensão reconhecida (ex.: upload de /tmp/upload_8f3a).
            const byBlob = guessMimeType(blob);
            const contentType = byBlob !== DEFAULT_MIME_TYPE ? byBlob : guessMimeType(path);

            return await this.upload(blob, stream, { contentType, ...options });
        } finally {
            // Fecha o arquivo também quando o upload falha no meio da leitura.
            stream.destroy();
        }
    }

    /** Substitui os metadados do blob. */
    async setMetadata(blob: string, metadata: Readonly<Record<string, string | number | boolean>>): Promise<true> {
        this.guardWrites('setMetadata');

        await this.rest.request('PUT', this.path(blob), {
            query: { comp: 'metadata' },
            headers: metadataHeaders(metadata),
        });

        return true;
    }

    /** Remove um blob. `false` quando ele já não existia. */
    async delete(blob: string): Promise<boolean> {
        this.guardWrites('delete');

        const response = await this.rest.request('DELETE', this.path(blob), {
            headers: { 'x-ms-delete-snapshots': 'include' },
            allow: [404],
        });

        return response.status !== 404;
    }

    /** Remove todos os blobs sob um prefixo. Devolve a quantidade removida. */
    async deleteDirectory(prefix: string): Promise<number> {
        this.guardWrites('deleteDirectory');

        const directory = directoryPrefix(prefix);

        // Um prefixo vazio apagaria o container inteiro — raramente é a intenção.
        if (directory === '') {
            throw new AzureBlobError(
                'azure-blob: deleteDirectory() exige um prefixo; para esvaziar o container, apague blob a blob.',
                { context: { container: this.containerName() } },
            );
        }

        let removed = 0;

        for await (const item of this.listAll(directory)) {
            // O nome vem do próprio Azure: vai sem normalização, ou blobs como
            // "dir//a" ou "dir/a " seriam procurados com outro nome.
            const response = await this.rest.request('DELETE', `${this.containerName()}/${assertSafeName(item.name)}`, {
                headers: { 'x-ms-delete-snapshots': 'include' },
                allow: [404],
            });

            removed += response.status === 404 ? 0 : 1;
        }

        return removed;
    }

    /** Copia um blob, possivelmente entre containers. Devolve a URL do destino. */
    async copy(source: string, destination: string, options: CopyOptions = {}): Promise<string> {
        this.guardWrites('copy');

        const from = this.container(options.sourceContainer);
        const to = this.container(options.destinationContainer);

        const response = await to.rest.request('PUT', to.path(destination), {
            headers: { 'x-ms-copy-source': from.copySourceUrl(source) },
        });

        const status = response.headers.get('x-ms-copy-status');

        if (status === 'failed' || status === 'aborted') {
            throw copyFailed(destination, to.containerName(), status, response.headers);
        }

        if (options.wait === true && status === 'pending') {
            await to.waitForCopy(destination, options.timeout ?? 30);
        }

        return to.url(destination);
    }

    /** Copia e apaga a origem. */
    async move(source: string, destination: string, options: CopyOptions = {}): Promise<string> {
        const from = this.container(options.sourceContainer);
        const to = this.container(options.destinationContainer);

        // Copiar sobre si mesmo e depois apagar a origem apagaria o blob.
        if (from.path(source) === to.path(destination)) {
            throw new AzureBlobError(`azure-blob: origem e destino de move() são o mesmo blob ("${normalize(source)}").`, {
                context: { blob: normalize(source), container: from.containerName() },
            });
        }

        // A cópia entre containers é assíncrona no Azure; sem esperar, o delete
        // abaixo poderia apagar a origem antes de ela ser lida por completo.
        const url = await this.copy(source, destination, { ...options, wait: true });

        await this.container(options.sourceContainer).delete(source);

        return url;
    }

    // ========================================================================
    // Internos
    // ========================================================================

    /** Caminho completo `container/blob` usado pelo cliente REST. */
    path(blob: string): string {
        return `${this.containerName()}/${assertSafeName(normalize(blob))}`;
    }

    /**
     * URL que o serviço do Azure usará para ler a origem de uma cópia. Precisa
     * carregar credencial própria: o serviço lê a origem por conta dele, sem
     * os cabeçalhos da nossa requisição.
     */
    private copySourceUrl(blob: string): string {
        const config = this.rest.config;

        if (config.usesSasToken()) {
            return `${this.url(blob)}?${config.sasToken}`;
        }

        return `${this.url(blob)}?${this.rest.sas().forBlob(this.containerName(), blob, 1, normalizePermissions('r'))}`;
    }

    /** Páginas de uma listagem rasa, seguindo o `NextMarker`. */
    private async *directoryPages(path: string): AsyncGenerator<BlobList> {
        let marker: string | null = null;

        do {
            const page: BlobList = await this.list(directoryPrefix(path), MAX_PAGE, { delimiter: '/', marker });

            yield page;

            marker = page.nextMarker;
        } while (marker !== null);
    }

    /** Aguarda uma cópia assíncrona terminar. */
    private async waitForCopy(blob: string, timeout: number): Promise<void> {
        const deadline = Date.now() + Math.max(1, timeout) * 1000;

        do {
            const response = await this.rest.request('HEAD', this.path(blob));
            const status = response.headers.get('x-ms-copy-status');

            // Só "success" é sucesso: com "failed"/"aborted", um move() que
            // seguisse adiante apagaria a origem de uma cópia que não existe.
            if (status === 'failed' || status === 'aborted') {
                throw copyFailed(blob, this.containerName(), status, response.headers);
            }

            if (status !== 'pending') {
                return;
            }

            await new Promise((resolve) => setTimeout(resolve, 250));
        } while (Date.now() < deadline);

        throw new AzureBlobError(`azure-blob: a cópia para "${blob}" não terminou em ${timeout} segundos.`, {
            context: { blob, container: this.containerName(), timeout },
        });
    }

    private guardWrites(operation: string): void {
        if (this.rest.config.readonly) {
            throw ReadOnlyError.for(this.rest.config.connection, operation);
        }
    }
}

function copyFailed(blob: string, container: string, status: string, headers: Headers): AzureBlobError {
    const description = headers.get('x-ms-copy-status-description');

    return new AzureBlobError(
        `azure-blob: a cópia para "${blob}" terminou com status "${status}"${description ? ` (${description})` : ''}.`,
        { context: { blob, container, copy_status: status, copy_status_description: description } },
    );
}
