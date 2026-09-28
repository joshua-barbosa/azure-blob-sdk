import { defineConfig } from 'tsup';

const shared = {
    target: 'node20',
    platform: 'node',
    sourcemap: false,
    // As integrações dependem de pacotes que o usuário instala por conta
    // própria; embuti-los duplicaria o framework dentro do SDK.
    external: ['@nestjs/common', 'flydrive'],
} as const;

export default defineConfig([
    {
        ...shared,
        entry: {
            index: 'src/index.ts',
            nestjs: 'src/integrations/nestjs.ts',
            flydrive: 'src/integrations/flydrive.ts',
        },
        format: ['esm', 'cjs'],
        dts: true,
        clean: true,
        // As entradas compartilham um chunk com o núcleo. Sem isso cada uma
        // teria a própria cópia de BlobClient/BlobManager, e o `instanceof` do
        // driver e a injeção por tipo no Nest falhariam para quem importa do
        // pacote principal.
        splitting: true,
    },
    {
        // O executável usa top-level await e import.meta: só existe em ESM.
        ...shared,
        entry: { cli: 'src/cli/bin.ts' },
        format: ['esm'],
        splitting: false,
    },
]);
