import { type DynamicModule, Inject, type InjectionToken, type ModuleMetadata, type Provider } from '@nestjs/common';
import { BlobClient } from '../blob-client.js';
import { BlobManager, type ManagerOptions } from '../blob-manager.js';

/**
 * Integração com o NestJS.
 *
 *     @Module({
 *         imports: [AzureBlobModule.forRoot({ isGlobal: true, clients: ['contratos'] })],
 *     })
 *     export class AppModule {}
 *
 *     @Injectable()
 *     export class ApostilasService {
 *         constructor(
 *             private readonly azure: BlobManager,                      // o gerenciador
 *             @InjectBlob() private readonly blob: BlobClient,          // conexão padrão
 *             @InjectBlob('contratos') private readonly contratos: BlobClient,
 *         ) {}
 *     }
 *
 * Sem opções de conexão, o módulo lê as variáveis de ambiente — as mesmas do
 * SDK PHP.
 */

export interface AzureBlobModuleOptions extends ManagerOptions {
    /** Registra o módulo como global, dispensando importá-lo em cada módulo. */
    isGlobal?: boolean;
    /** Conexões nomeadas que ganham um provider próprio para `@InjectBlob(nome)`. */
    clients?: readonly string[];
}

export interface AzureBlobModuleAsyncOptions extends Pick<ModuleMetadata, 'imports'> {
    isGlobal?: boolean;
    clients?: readonly string[];
    inject?: InjectionToken[];
    useFactory: (...args: never[]) => ManagerOptions | Promise<ManagerOptions>;
}

/** Token das opções resolvidas do gerenciador. */
export const AZURE_BLOB_OPTIONS = Symbol('AZURE_BLOB_OPTIONS');

/** Token do cliente de uma conexão; sem nome, a conexão padrão. */
export function getBlobToken(connection?: string): string {
    return connection === undefined ? 'AzureBlobClient' : `AzureBlobClient:${connection}`;
}

/** Injeta o BlobClient de uma conexão (sem nome: a padrão). */
export function InjectBlob(connection?: string): PropertyDecorator & ParameterDecorator {
    return Inject(getBlobToken(connection));
}

/** Injeta o BlobManager. Equivale a tipar o parâmetro como `BlobManager`. */
export function InjectBlobManager(): PropertyDecorator & ParameterDecorator {
    return Inject(BlobManager);
}

export class AzureBlobModule {
    static forRoot(options: AzureBlobModuleOptions = {}): DynamicModule {
        const { isGlobal, clients, ...managerOptions } = options;

        return build({ provide: AZURE_BLOB_OPTIONS, useValue: managerOptions }, [], isGlobal, clients);
    }

    static forRootAsync(options: AzureBlobModuleAsyncOptions): DynamicModule {
        return build(
            {
                provide: AZURE_BLOB_OPTIONS,
                useFactory: options.useFactory as (...args: unknown[]) => ManagerOptions | Promise<ManagerOptions>,
                inject: options.inject ?? [],
            },
            options.imports ?? [],
            options.isGlobal,
            options.clients,
        );
    }
}

function build(
    optionsProvider: Provider,
    imports: NonNullable<ModuleMetadata['imports']>,
    isGlobal: boolean | undefined,
    clients: readonly string[] | undefined,
): DynamicModule {
    const managerProvider: Provider = {
        provide: BlobManager,
        useFactory: (resolved: ManagerOptions) =>
            // Sem conexões declaradas, vale o ambiente — com http/logger das opções.
            resolved.connections === undefined
                ? BlobManager.fromEnv(process.env, resolved)
                : new BlobManager(resolved),
        inject: [AZURE_BLOB_OPTIONS],
    };

    const clientProviders: Provider[] = [undefined, ...(clients ?? [])].map((connection) => ({
        provide: getBlobToken(connection),
        useFactory: (manager: BlobManager): BlobClient => manager.connection(connection),
        inject: [BlobManager],
    }));

    // BlobClient como classe também resolve para a conexão padrão, para quem
    // prefere injetar por tipo em vez de usar @InjectBlob().
    const byType: Provider = { provide: BlobClient, useExisting: getBlobToken() };

    const providers = [optionsProvider, managerProvider, ...clientProviders, byType];

    return {
        module: AzureBlobModule,
        global: isGlobal ?? false,
        imports,
        providers,
        exports: [BlobManager, BlobClient, ...clientProviders.map((provider) => (provider as { provide: string }).provide)],
    };
}
