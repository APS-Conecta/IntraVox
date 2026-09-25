/**
 * The shared JS text rules (L0-03/L0-04): one fold, one match, one
 * retired-list doctrine — accessor-parameterized the way epidemiologia's
 * useFilters parameterizes, so domain predicates compose on top.
 *
 * Vendored into each app's src/aps/ by `make aps-sync`; the corpus beside it
 * (fold-corpus.json) is folded identically by the PHP suite and by every
 * app's js suite — the cross-language contract.
 */
export const fold = (value) => String(value ?? '')
	.normalize('NFD')
	.replace(/\p{Diacritic}/gu, '')
	.toLowerCase()

/**
 * Whether a record answers to what someone typed: substring over folded
 * text, no ranking. Callers resolve their record's fields (including any
 * label looked up through an index) into the strings compared here.
 */
export function textMatches(query, fields) {
	const needle = fold(query).trim()
	if (needle === '') {
		return true
	}

	return fields.some((field) => fold(field).includes(needle))
}

/**
 * What a list shows: the narrowed live set, or the retired recovery screen.
 *
 * The retired list is deliberately NOT narrowed by the browsing filter —
 * it is the recovery screen (territorio#ADR-0005), and gating it on a
 * forgotten toggle tells someone the record they are looking for was
 * destroyed. The search DOES reach it: a query is someone saying out loud
 * what they are looking for, and ignoring it would overrule an explicit
 * instruction. Stated once here; both apps' domain filters pass their own
 * narrowing and matching as callbacks.
 */
export function listFor({ live, retired, showRetired, filterLive, matchesOf }) {
	if (!showRetired) {
		return filterLive(live)
	}

	return retired.filter(matchesOf)
}
