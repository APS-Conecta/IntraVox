/**
 * check-aps-parity: the heading-anchor slugs stay byte-stable.
 *
 * headingAnchors.slugifyHeading folds through aps-common's shared rule
 * (src/aps/filters.js, L0-03). Anchor ids are baked into saved pages and
 * shared deep links — a fold change in the canon that alters any slug below
 * silently breaks every existing fragment URL. This gate fails the build
 * before that ships: the golden pairs are the outputs measured from the
 * pre-convergence implementation (parity verified 2026-09-25 across the
 * org's fold corpus plus en/nl/es headings, ligatures, truncation, empty
 * and non-Latin input).
 *
 * A deliberate change to slugging is allowed — update this table in the same
 * commit, and say why; "the canon moved" is only acceptable if every entry
 * still matches or the change is explicitly a breaking anchor migration.
 */
import { slugifyHeading } from '../src/utils/headingAnchors.js';

const GOLDEN = [
	// The org's fold corpus (Spanish labels every consumer folds identically)
	['Ñuñoa', 'nunoa'],
	['Jardín Infantil Rayún', 'jardin-infantil-rayun'],
	['Peñaflor', 'penaflor'],
	['CESFAM Bellavista', 'cesfam-bellavista'],
	['Analgésicos', 'analgesicos'],
	['Deporte y recreación', 'deporte-y-recreacion'],
	// This app's own heading shapes (en / nl / es)
	['Creating a new form', 'creating-a-new-form'],
	['Nieuwe pagina toevoegen', 'nieuwe-pagina-toevoegen'],
	['Configuración del sitio', 'configuracion-del-sitio'],
	['Contact & openingstijden', 'contact-openingstijden'],
	// HTML in headings, compat characters, separators
	['<b>Hallo</b> wereld', 'hallo-wereld'],
	['\uFB01le bestanden', 'file-bestanden'], // fi ligature folds via NFKD
	['§ 3.2 — Verwijzingen', '3-2-verwijzingen'],
	['a\u2460b', 'a1b'], // circled one: NFKD folds it to the digit 1
	['Vlidéŗāsčïō — über ﬂuß', 'vliderascio-uber-flu'], // ﬂ ligature folds; ß has no decomposition and lands as a separator
	// Degenerate and non-Latin input
	['Ёлка', 'section'], // Cyrillic: nothing survives → fallback
	['', 'section'],
	['<br>', 'section'],
	['   ', 'section'],
	// The 80-char ceiling
	['x'.repeat(100), 'x'.repeat(80)],
	['Traición — ¿quién eres?', 'traicion-quien-eres'],
];

let failures = 0;
for (const [input, expected] of GOLDEN) {
	const actual = slugifyHeading(input);
	if (actual !== expected) {
		failures++;
		console.error(`  ${JSON.stringify(input)}\n      expected: ${JSON.stringify(expected)}\n      actual:   ${JSON.stringify(actual)}`);
	}
}

if (failures > 0) {
	console.error(`check-aps-parity: ${failures} heading slug(s) moved — existing deep links break.`);
	console.error('  If the fold canon changed deliberately, update the golden table in the same');
	console.error('  commit and treat it as an anchor migration, not a free refactor.');
	process.exit(1);
}
console.log(`check-aps-parity: ${GOLDEN.length} heading slugs byte-stable (aps-common fold, L0-03)`);
