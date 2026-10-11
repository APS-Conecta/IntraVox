/**
 * Simulate the Nextcloud Transifex bot's string extraction for src/.
 *
 * Our own extractor (scripts/extract-en-json.js) is deliberately permissive:
 * it recognises every call form, so OUR POT is complete. But our POT is not
 * what reaches Transifex. The sync bot runs `translationtool.phar
 * create-pot-files`, which overwrites the template with its own extraction,
 * pushes that, and deletes the file. A string its extractor cannot see does
 * not exist for translators — that is how IntraVox sat at ~8% in June 2026
 * (414 of 1213 strings visible) while every local check was green.
 *
 * Transcribed from nextcloud/docker-ci translations/translationtool/src/
 * translationtool.php (read 2026-10-09). Two passes:
 *
 *   <template>  its own regex, matching  t('appid', '…')  literally
 *   <script>    spliced into one .js file and handed to
 *               xgettext --keyword=t:2 --keyword=n:2,3
 *
 * So the helper must be called t (or n) and the string must be the SECOND
 * argument — the app id first. `$t('…')`, `translate('intravox', KEY)` and a
 * one-argument `t('…')` all work at runtime and are all invisible.
 *
 * PHP needs no simulation: the bot's xgettext --keyword=t on PHP accepts
 * exactly the literal `$l->t('…')` form that extract-en-json.js recognises.
 */

const fs = require('fs')
const path = require('path')
const { stringsInContent } = require('../extract-en-json.js')

const APP_ID = 'intravox'

// translationtool.php collapses whitespace in a template match with PHP's
// preg_replace('/\s+/', ' '). PHP's \s is ASCII-only, so a U+00A0 survives;
// JavaScript's \s also matches NBSP and would turn "Loading\u00a0…" into
// "Loading …", reporting strings that the bot does see as missing.
const collapsePhpWhitespace = (s) => s.replace(/[ \t\n\r\f\v]+/g, ' ')

const unescape = (s) => s.replace(/\\(['\\])/g, '$1')

function ignoredPatterns(root) {
	const file = path.join(root, '.l10nignore')
	if (!fs.existsSync(file)) return []
	return fs.readFileSync(file, 'utf8').split('\n')
		.map((l) => l.trim()).filter((l) => l && !l.startsWith('#'))
		.map((p) => p.replace(/\/$/, ''))
}

function sourceFiles(root) {
	const ignored = ignoredPatterns(root)
	const isIgnored = (rel) => ignored.some((p) => rel === p || rel.startsWith(p + '/'))
	const out = []
	const walk = (dir) => {
		for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
			const full = path.join(dir, e.name)
			if (isIgnored(path.relative(root, full))) continue
			if (e.isDirectory()) walk(full)
			else if (/\.(vue|js)$/.test(e.name)) out.push(full)
		}
	}
	walk(path.join(root, 'src'))
	return out
}

/** The msgids the bot's two passes extract from one file. */
function botSees(file) {
	const source = fs.readFileSync(file, 'utf8')
	const found = new Set()
	const isVue = file.endsWith('.vue')

	// <template>: translationtool.php's regex, but non-greedy per call. The
	// real one is greedy ('(.+)'), so two calls on one line become one match —
	// which it re-emits as JS that xgettext then splits again, so nothing is
	// lost. Matching each call separately asks the useful question: does every
	// individual call have the app id?
	const template = isVue ? source.match(/<template>([\s\S]+)<\/template>/) : null
	if (template) {
		for (const m of template[1].matchAll(/\Wt\s*\(\s*'?([\w.]+)'?,\s*'((?:[^'\\]|\\.)*)'/g)) {
			if (m[1] === APP_ID) found.add(collapsePhpWhitespace(unescape(m[2])))
		}
		for (const m of template[1].matchAll(/\Wn\s*\(\s*'?([\w.]+)'?,\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'/g)) {
			if (m[1] === APP_ID) found.add(collapsePhpWhitespace(unescape(m[2])))
		}
	}

	// <script> or a plain .js: xgettext --keyword=t:2 / --keyword=n:2,3. A
	// member call (this.t) is still the keyword t to xgettext; $t is not.
	const scripts = isVue
		? [...source.matchAll(/<script[^>]*>([\s\S]+?)<\/script>/g)].map((m) => m[1])
		: [source]
	for (const body of scripts) {
		for (const m of body.matchAll(new RegExp(`(?<![\\w$])[tn]\\(\\s*'${APP_ID}'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'`, 'g'))) {
			found.add(unescape(m[1]))
		}
	}
	return found
}

/**
 * @param {string} root repository root
 * @returns {{ checked: number, visible: number, invisible: string[], offenders: string[] }}
 */
function checkBotVisibility(root) {
	const visible = new Set()
	const ours = new Set()
	const bodies = []
	for (const f of sourceFiles(root)) {
		const body = fs.readFileSync(f, 'utf8')
		bodies.push([f, body])
		for (const s of botSees(f)) visible.add(s)
		// What our own extractor takes from this file: the strings src/ is
		// supposed to hand to the bot. (PHP is out of scope, see the header.)
		for (const s of stringsInContent(body)) ours.add(s)
	}
	const invisible = [...ours].filter((s) => !visible.has(s)).sort()

	// The causes rather than the symptom, so a new one is caught where it is
	// written, not when someone notices an English screen:
	const offenders = []
	const at = (f, m) => `${path.relative(root, f)}: ${m[0].replace(/\s+/g, ' ').slice(0, 70)}`
	for (const [f, body] of bodies) {
		// 1. A call whose first argument is not the app id (t('Save'), $t('Save')).
		//    A literal with a space in it is a string, not an app id.
		for (const m of body.matchAll(/(?<![\w$'])\$?[tn]\(\s*'((?:[^'\\]|\\.)*)'\s*[,)]/g)) {
			if (m[1] !== APP_ID && /\s/.test(m[1])) offenders.push(at(f, m))
		}
		// 2. A dynamic lookup: the app id, then a variable instead of a literal
		//    (translate('intravox', MAP[key])). Translate each literal where the
		//    map is defined instead.
		for (const m of body.matchAll(new RegExp(`(?<![\\w$])(?:t|n|translate|translatePlural)\\(\\s*'${APP_ID}'\\s*,\\s*(?!['\`])[\\w$.[\\]]+`, 'g'))) {
			offenders.push(at(f, m))
		}
		// 3. A wrapper named anything but t/n: xgettext only knows those keywords.
		for (const m of body.matchAll(/\b(?:const|let|var)\s+(\w+)\s*=\s*\([^)]*\)\s*=>\s*(?:\{\s*return\s+)?translate(?:Plural)?\(/g)) {
			if (m[1] !== 't' && m[1] !== 'n') offenders.push(at(f, m))
		}
	}
	return { checked: ours.size, visible: visible.size, invisible, offenders }
}

module.exports = { checkBotVisibility, botSees }
