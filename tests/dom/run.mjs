/**
 * DOM harness runner: `npm run test:dom`.
 *
 * Sets up jsdom globals, bundles tests/dom/entry.jsx with esbuild
 * (aliasing '@inertiajs/react' to the harness stub), then executes the
 * scenarios. Exits non-zero when any check fails.
 */
import { JSDOM } from 'jsdom';
import * as esbuild from 'esbuild';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

const dom = new JSDOM('<!doctype html><html><head></head><body></body></html>', {
    url: 'http://localhost/',
    pretendToBeVisual: true,
});
globalThis.window = dom.window;
globalThis.document = dom.window.document;
Object.defineProperty(globalThis, 'navigator', { value: dom.window.navigator, configurable: true });
globalThis.HTMLElement = dom.window.HTMLElement;
globalThis.HTMLInputElement = dom.window.HTMLInputElement;
globalThis.HTMLSelectElement = dom.window.HTMLSelectElement;
globalThis.HTMLTextAreaElement = dom.window.HTMLTextAreaElement;
globalThis.Element = dom.window.Element;
globalThis.Node = dom.window.Node;
globalThis.Event = dom.window.Event;
globalThis.CustomEvent = dom.window.CustomEvent;
globalThis.MouseEvent = dom.window.MouseEvent;
globalThis.KeyboardEvent = dom.window.KeyboardEvent;
globalThis.File = dom.window.File;
globalThis.FormData = dom.window.FormData;
globalThis.localStorage = dom.window.localStorage;
globalThis.requestAnimationFrame = (cb) => setTimeout(cb, 0);
globalThis.cancelAnimationFrame = (id) => clearTimeout(id);
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
if (!globalThis.matchMedia) {
    globalThis.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
}
dom.window.matchMedia = globalThis.matchMedia;

const outdir = mkdtempSync(join(tmpdir(), 'tpda-dom-harness-'));
const outfile = join(outdir, 'bundle.mjs');

await esbuild.build({
    entryPoints: [join(here, process.env.DOM_ENTRY ?? 'entry.jsx')],
    bundle: true,
    format: 'esm',
    platform: 'browser',
    jsx: 'automatic',
    outfile,
    alias: { '@inertiajs/react': join(here, 'inertia-stub.jsx') },
    loader: { '.json': 'json' },
    logLevel: 'warning',
    define: { 'process.env.NODE_ENV': '"development"' },
});

let harnessResult = null;
const originalLog = console.log;
console.log = (...args) => {
    const line = args.join(' ');
    if (line.startsWith('HARNESS_RESULT ')) {
        harnessResult = JSON.parse(line.slice('HARNESS_RESULT '.length));
    }
    originalLog(...args);
};
await import(pathToFileURL(outfile).href);
console.log = originalLog;

if (!harnessResult) {
    console.error('Harness produced no result line — bundle crashed?');
    process.exit(1);
}
console.log(`\nDOM harness: ${harnessResult.pass} passed, ${harnessResult.fail} failed`);
process.exit(harnessResult.fail > 0 ? 1 : 0);
