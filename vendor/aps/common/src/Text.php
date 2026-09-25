<?php

declare(strict_types=1);

namespace APS\Common;

/**
 * The es-CL text rules every APS Conecta app folds and slugs by, stated once
 * (L0-03). Decompose and drop the combining marks rather than mapping the
 * seven Spanish letters: a clinic types what it types, and a list of seven
 * is a list that is missing one.
 *
 * Depends on no OCP class — bare PHP.
 */
final class Text {
	/** The cap every consumer's slug column already carries (VARCHAR(64)). */
	public const SLUG_LENGTH = 64;

	/**
	 * Text as it is compared: accents off, case down, runs of whitespace one
	 * space. The duplicate queue's own rule (territorio DuplicateFinder::fold),
	 * extracted — a CSV column with a doubled space is a spelling difference
	 * too. Note the asymmetry the twins test pins: the JS fold collapses
	 * nothing, so a search needle keeps its internal spacing.
	 */
	public static function fold(string $value): string {
		$decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);
		$stripped = preg_replace('/\p{Mn}/u', '', $decomposed === false ? $value : $decomposed);

		return trim((string)preg_replace('/\s+/', ' ', mb_strtolower((string)$stripped)));
	}

	/**
	 * The identifier a label makes: accents off, case down, everything else
	 * a separator. Byte-identical to the two services' rule it replaces
	 * (TaxonomyService::slugFor ≡ VocabularyService::slugFor); both keep a
	 * thin static forward so existing call sites and tests are untouched.
	 */
	public static function slugFor(string $label): string {
		$decomposed = \Normalizer::normalize($label, \Normalizer::FORM_D);
		$stripped = preg_replace('/\p{Mn}/u', '', $decomposed === false ? $label : $decomposed);
		$slug = (string)preg_replace('/[^a-z0-9]+/', '_', mb_strtolower((string)$stripped));

		// Trimmed again after the cut, so a name cut mid-separator does not
		// make a slug the same label would not make twice.
		return trim(mb_substr(trim($slug, '_'), 0, self::SLUG_LENGTH), '_');
	}
}
