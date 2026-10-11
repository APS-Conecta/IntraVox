#!/usr/bin/env node
/**
 * Prebuild guard: fail the build if the code's translatable-string set no longer
 * matches the committed manifest (l10n/.source-strings.json).
 *
 * Why: IntraVox translations round-trip through the Nextcloud Transifex bot. New
 * source strings only become translatable once `npm run l10n:push` regenerates
 * translationfiles/templates/intravox.pot + this manifest and they are committed
 * to GitHub main (the bot reads the POT from there). If a developer adds strings
 * but skips that, the bot never sees them, translators can't translate them, and
 * the NEXT bot sync DELETES those strings from l10n/<lang>.{js,json}. That is the
 * recurring "feature strings disappear on release" bug.
 *
 * This guard makes the omission a hard build error the first time you build after
 * adding a string — with maximum lead time for translators. It keys off the
 * durable manifest (a committed sha256 + sorted msgid list), NOT the POT file,
 * because the bot deletes the POT after ingesting it (that delete is normal).
 *
 * Pure Node, no xgettext: extract-en-json.js already scans lib/**.php too, so its
 * computeSourceStrings() is the complete source set for both frontend and PHP.
 *
 * Second check, same reason: a string in the manifest still never reaches
 * translators if the bot's own extractor cannot see it (the June 2026 bug: 414
 * of 1213 strings visible). scripts/lib/l10n-bot-visibility.js simulates that
 * extractor over src/ and fails on any string it would miss, and on the call
 * forms that cause it (missing app id, dynamic lookup, a helper not named t/n).
 *
 * Usage: node scripts/check-l10n-sync.js   (also wired into `prebuild`)
 */

const fs = require('fs');
const path = require('path');
const { computeSourceStrings, sourceStringManifest } = require('./extract-en-json.js');
const { checkBotVisibility } = require('./lib/l10n-bot-visibility.js');

const ROOT = path.resolve(__dirname, '..');
const MANIFEST = path.join(ROOT, 'l10n', '.source-strings.json');
const REL = path.relative(ROOT, MANIFEST);

const REMEDIATION = `
Fix (decoupled from release — do this now, not at release time):
  npm run l10n:push
  git add translationfiles/templates/intravox.pot l10n/.source-strings.json l10n/.source-count.json
  git commit -s -m "l10n: push <N> new source strings to Transifex"
  git push github main          # the bot reads the POT from GitHub only

Then let the Nextcloud bot ingest the POT and translators work before you cut a
release. Do NOT hand-edit l10n/<lang>.{js,json} and do NOT run \`npm run
l10n:generate-js\` to "fix" it — that drops strings and desyncs .js from .json.
`;

function fail(msg) {
	console.error('\n\x1b[31m✗ l10n source strings out of sync\x1b[0m');
	console.error(msg);
	console.error(REMEDIATION);
	process.exit(1);
}

// 1. Current source-string set straight from the code.
const actual = sourceStringManifest(computeSourceStrings());

// 2. The committed manifest.
if (!fs.existsSync(MANIFEST)) {
	fail(`Manifest ${REL} is missing.\nCode currently has ${actual.count} source strings.`);
}
let manifest;
try {
	manifest = JSON.parse(fs.readFileSync(MANIFEST, 'utf8'));
} catch (e) {
	fail(`Manifest ${REL} is not valid JSON: ${e.message}`);
}

// 3. Compare on the hash (catches add + remove no-ops a count check would miss).
if (actual.sha256 === manifest.sha256) {
	console.log(`✓ l10n source strings in sync (${actual.count} strings)`);
	botVisibility();
	process.exit(0);
}

// The Transifex bot's view of src/ (see the header).
function botVisibility() {
	const bot = checkBotVisibility(ROOT);
	if (bot.invisible.length === 0 && bot.offenders.length === 0) {
		console.log(`✓ every frontend string is visible to the Transifex bot (${bot.checked} strings)`);
		return;
	}
	const show = (arr) => arr.slice(0, 40).map(s => `      ${JSON.stringify(s)}`).join('\n')
		+ (arr.length > 40 ? `\n      … and ${arr.length - 40} more` : '');
	console.error('\n\x1b[31m✗ l10n strings the Transifex bot cannot see\x1b[0m');
	if (bot.invisible.length) console.error(`\n  Never reach translators (${bot.invisible.length}):\n${show(bot.invisible)}`);
	if (bot.offenders.length) console.error(`\n  Call sites the bot's extractor cannot read (${bot.offenders.length}):\n${show(bot.offenders)}`);
	console.error(`
Fix: call a helper named t/n with the app id first and a literal string —
  t('intravox', 'Save')  /  n('intravox', '%n page', '%n pages', count)
not t('Save'), $t('Save'), translate('intravox', MAP[key]) or a wrapper named
anything else. For a lookup table, make each entry its own literal t() call.
`);
	process.exit(1);
}

// 4. Out of sync — show exactly what entered/left so the diff is actionable.
const committed = new Set(Array.isArray(manifest.strings) ? manifest.strings : []);
const current = new Set(actual.msgids);
const added = actual.msgids.filter(s => !committed.has(s));
const removed = [...committed].filter(s => !current.has(s));

const CAP = 40;
const list = (arr) => arr.slice(0, CAP).map(s => `      ${JSON.stringify(s)}`).join('\n')
	+ (arr.length > CAP ? `\n      … and ${arr.length - CAP} more` : '');

let msg = `  manifest: ${manifest.count} strings (${String(manifest.sha256).slice(0, 12)}…)\n`;
msg += `  code:     ${actual.count} strings (${actual.sha256.slice(0, 12)}…)\n\n`;
msg += `  +${added.length} added   −${removed.length} removed\n`;
if (added.length) msg += `\n  Added (in code, not yet pushed to Transifex):\n${list(added)}\n`;
if (removed.length) msg += `\n  Removed (in the manifest, no longer in code):\n${list(removed)}\n`;

fail(msg);
