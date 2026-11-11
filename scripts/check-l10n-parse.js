#!/usr/bin/env node
/**
 * Translation-file guard: every l10n/*.json parses as JSON and every l10n/*.js passes
 * `node --check`.
 *
 * Why: Nextcloud loads a language's l10n/<lang>.json on the server and l10n/<lang>.js in
 * the browser. A file that does not parse is dropped whole, so the interface falls back to
 * English with no error anywhere. The suite forces `es`, and a hand edit that lost one
 * comma (fork commit 38542949) turned every IntraVox text on «Inicio» English
 * (APS-Conecta/gestion-aio#126). A Transifex sync or a hand edit can no longer do that
 * silently: this guard runs in `prebuild` and in CI.
 *
 * Pure Node, no dependencies. Usage: node scripts/check-l10n-parse.js [dir]
 */

'use strict';

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const dir = path.resolve(process.argv[2] || path.join(__dirname, '..', 'l10n'));
const files = fs.readdirSync(dir).filter((name) => /\.(json|js)$/.test(name)).sort();
const broken = [];

for (const name of files) {
	const file = path.join(dir, name);
	try {
		if (name.endsWith('.json')) {
			JSON.parse(fs.readFileSync(file, 'utf8'));
		} else {
			execFileSync(process.execPath, ['--check', file], { stdio: 'pipe' });
		}
	} catch (error) {
		const lines = error.stderr ? error.stderr.toString().trim().split('\n') : [];
		const detail = lines.length ? `${path.basename(lines[0])} ${lines.find((line) => /Error/.test(line)) || ''}`.trim() : error.message;
		broken.push(`l10n/${name}: ${detail}`);
	}
}

if (broken.length) {
	console.error(`check-l10n-parse: ${broken.length} translation file(s) do not parse:\n  ${broken.join('\n  ')}`);
	process.exit(1);
}
console.log(`check-l10n-parse: ${files.length} translation files parse`);
