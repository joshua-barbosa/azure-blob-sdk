import 'reflect-metadata';
import { Injectable, Module } from '@nestjs/common';
import { Test } from '@nestjs/testing';
import { describe, expect, it } from 'vitest';
import { BlobClient } from '../../src/blob-client.js';
import { BlobManager } from '../../src/blob-manager.js';
import {
    AZURE_BLOB_OPTIONS,
    AzureBlobModule,
    InjectBlob,
    InjectBlobManager,
    getBlobToken,
} from '../../src/integrations/nestjs.js';
import { KEY_CONNECTION, SAS_CONNECTION } from '../helpers/clients.js';
import { ACCOUNT_URL, FakeAzure, SAS_TOKEN } from '../helpers/fake-azure.js';

// Sem o transformador de decorators do tsc, os decorators de parâmetro são
// aplicados à mão — o efeito é o mesmo de `constructor(@InjectBlob() ...)`.
class ApostilasService {
    constructor(
        readonly manager: BlobManager,
        readonly blob: BlobClient,
        readonly contratos: BlobClient,
    ) {}
}
InjectBlobManager()(ApostilasService, undefined as unknown as string, 0);
InjectBlob()(ApostilasService, undefined as unknown as string, 1);
InjectBlob('contratos')(ApostilasService, undefined as unknown as string, 2);
Injectable()(ApostilasService);

describe('AzureBlobModule', () => {
    it('forRoot registra gerenciador, conexão padrão e conexões nomeadas', async () => {
        const azure = new FakeAzure();
        azure.put('docs/a.txt', 'olá');

        const moduleRef = await Test.createTestingModule({
            imports: [
                AzureBlobModule.forRoot({
                    connections: { default: KEY_CONNECTION, contratos: SAS_CONNECTION },
                    http: { fetch: azure.fetch },
                    clients: ['contratos'],
                }),
            ],
            providers: [ApostilasService],
        }).compile();

        const service = moduleRef.get(ApostilasService);

        expect(service.manager).toBeInstanceOf(BlobManager);
        expect(service.blob).toBe(service.manager.connection());
        expect(service.contratos.info()).toContain('Auth: SAS URL');
        expect(moduleRef.get(BlobClient)).toBe(service.blob);
        expect(moduleRef.get(getBlobToken('contratos'))).toBe(service.contratos);
        expect((await service.blob.get('a.txt')).toString()).toBe('olá');
    });

    it('forRootAsync resolve as opções com dependências injetadas e pode ser global', async () => {
        const CONFIG = Symbol('CONFIG');

        class ConfigModule {}
        Module({ providers: [{ provide: CONFIG, useValue: { container: 'async' } }], exports: [CONFIG] })(ConfigModule);

        class FeatureModule {}
        Module({ providers: [ApostilasService] })(FeatureModule);

        const moduleRef = await Test.createTestingModule({
            imports: [
                AzureBlobModule.forRootAsync({
                    isGlobal: true,
                    imports: [ConfigModule],
                    inject: [CONFIG],
                    clients: ['contratos'],
                    useFactory: async (config: { container: string }) => ({
                        connections: {
                            default: { ...KEY_CONNECTION, container: config.container },
                            contratos: SAS_CONNECTION,
                        },
                    }),
                }),
                FeatureModule,
            ],
        }).compile();

        expect(moduleRef.get(ApostilasService).blob.containerName()).toBe('async');
        expect(moduleRef.get(AZURE_BLOB_OPTIONS)).toHaveProperty('connections');
    });

    it('sem conexões declaradas, lê o ambiente', async () => {
        const previous = process.env['AZURE_STORAGE_SAS_URL'];
        process.env['AZURE_STORAGE_SAS_URL'] = `${ACCOUNT_URL}/do-env?${SAS_TOKEN}`;

        try {
            const moduleRef = await Test.createTestingModule({ imports: [AzureBlobModule.forRoot()] }).compile();

            expect(moduleRef.get(BlobClient).containerName()).toBe('do-env');
            expect(AzureBlobModule.forRoot({ isGlobal: true }).global).toBe(true);
        } finally {
            if (previous === undefined) {
                delete process.env['AZURE_STORAGE_SAS_URL'];
            } else {
                process.env['AZURE_STORAGE_SAS_URL'] = previous;
            }
        }
    });
});
