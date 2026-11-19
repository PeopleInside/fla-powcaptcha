#!/usr/bin/env node
/**
 * The extension version lives in ONE place: the `VERSION` file at the root.
 *
 *  - PHP (widget, API) reads `VERSION` at runtime: nothing to sync.
 *  - This script copies it to the files that cannot read it themselves:
 *    js/package.json and the build id inside js/dist/*.js.
 *
 *   node scripts/sync-version.js          write the version everywhere
 *   node scripts/sync-version.js --check  only verify (exit 1 on mismatch)
 *
 * Release routine: edit VERSION, add a `## <version>` heading to CHANGELOG.md, run this.
 */
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const check = process.argv.includes('--check');
const version = fs.readFileSync(path.join(root, 'VERSION'), 'utf8').trim();

if (!/^\d+\.\d+\.\d+([-+][0-9A-Za-z.-]+)?$/.test(version)) {
    console.error(`Invalid version in VERSION: "${version}"`);
    process.exit(1);
}

const targets = [
    { file: 'js/package.json', re: /("version":\s*")[^"]*(")/, rep: `$1${version}$2` },
    { file: 'js/dist/admin.js', re: /(build=")[^"]*(")/, rep: `$1${version}$2` },
    { file: 'js/dist/forum.js', re: /(build=")[^"]*(")/, rep: `$1${version}$2` },
];

let ok = true;

for (const t of targets) {
    const p = path.join(root, t.file);
    const src = fs.readFileSync(p, 'utf8');
    const m = src.match(t.re);

    if (!m) {
        console.error(`✗ ${t.file}: version marker not found`);
        ok = false;
        continue;
    }

    const next = src.replace(t.re, t.rep);

    if (next === src) {
        console.log(`✓ ${t.file} is ${version}`);
    } else if (check) {
        console.error(`✗ ${t.file} is out of date (expected ${version})`);
        ok = false;
    } else {
        fs.writeFileSync(p, next);
        console.log(`↻ ${t.file} -> ${version}`);
    }
}

// The newest changelog section must be the current version.
const heading = (fs.readFileSync(path.join(root, 'CHANGELOG.md'), 'utf8').match(/^## (\S+)/m) || [])[1];

if (heading !== version) {
    console.error(`✗ CHANGELOG.md: newest section is "${heading}", expected "${version}"`);
    ok = false;
}

process.exit(ok ? 0 : 1);
