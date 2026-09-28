#!/usr/bin/env node
import { createRequire } from 'node:module';
import { BlobManager } from '../blob-manager.js';
import { processIo } from './io.js';
import { run } from './run.js';

const { version } = createRequire(import.meta.url)('../package.json') as { version: string };

process.exitCode = await run(
    process.argv.slice(2),
    processIo((options) => new BlobManager(options)),
    version,
);
