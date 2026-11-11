#!/usr/bin/env node
/**
 * Translation-file guard: every l10n/*.json parses as JSON and every l10n/*.js passes
 * `node --check`.
 *
 * Why: Nextcloud loads a language's l10n/<lang>.json on the server and l10n/<lang>.js in
 * the browser. A file that does not parse is dropped whole, so the interface falls back to
 * English with no error anywhere. The suite forces `es`, and a hand edit that lost one
 * comma turned every IntraVox text on «Inicio» English (APS-Conecta/gestion-aio#126). An
 * upstream pull or a hand edit can no longer do that silently: this guard runs in
 * `prebuild` and in CI.
 *
 * Pure Node, no dependencies. Usage: node scripts/check-l10n-parse.js
 */

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const dir = path.join(__dirname, '..', 'l10n');
const files = fs.readdirSync(dir).filter((name) => /\.(json|js)$/.test(name)).sort();

/** The parser's message for a file that does not parse: node's location and error line, or JSON.parse's message. */
function describe(error) {
	const lines = error.stderr ? error.stderr.toString().trim().split('\n') : [];
	if (!lines.length) {
		return error.message;
	}
	const message = lines.find((line) => /Error/.test(line)) || '';
	return `${path.basename(lines[0])} ${message}`.trim();
}

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
		broken.push(`l10n/${name}: ${describe(error)}`);
	}
}

if (broken.length) {
	console.error(`check-l10n-parse: ${broken.length} translation file(s) do not parse:\n  ${broken.join('\n  ')}`);
	process.exit(1);
}
console.log(`check-l10n-parse: ${files.length} translation files parse`);
