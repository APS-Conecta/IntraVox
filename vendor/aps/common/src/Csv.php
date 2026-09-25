<?php

declare(strict_types=1);

namespace APS\Common;

/**
 * The CSV dialect every APS Conecta app speaks, stated once.
 *
 * Extracted byte-identical from territorio's and farmacia's readers and
 * writers: the es-CL Excel reality both apps were built around. App-specific
 * shapes (territorio's lossy one-way export, farmacia's round-trip planilla)
 * stay in each app; this is the core they agree on.
 *
 * Depends on no OCP class — bare PHP, like the apps' own readers.
 */
final class Csv {
	/** Prepended to exports so Excel guesses UTF-8 instead of Latin-1.
	 *  LibreOffice asks; Excel does not, it just guesses. */
	public const BOM = "\u{FEFF}";

	/** The six first characters that make a spreadsheet run a cell as a formula. */
	private const FORMULA_TRIGGERS = "=+-@\t\r";

	/**
	 * The file as UTF-8, decided rather than guessed.
	 *
	 * Bytes that are not valid UTF-8 are read as Windows-1252, which is what
	 * Excel writes when it is not asked for UTF-8. That is a rule, not a
	 * heuristic — a 1252 file with any accent in it is invalid UTF-8, and a
	 * valid UTF-8 file is never touched. `mb_detect_encoding` would answer the
	 * same question by guessing, differently on different files.
	 *
	 * There is deliberately no BOM strip here. Excel writes one in front of a
	 * UTF-8 CSV and so does every writer using {@see BOM}, and it ends up on
	 * the first heading — but headings are matched on their slug, and the
	 * marker is neither a letter nor a digit, so the folding takes it off with
	 * the spaces and the accents.
	 */
	public static function utf8(string $csv): string {
		return mb_check_encoding($csv, 'UTF-8')
			? $csv
			: (string)mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
	}

	/**
	 * Which character separates the cells, read off the header line.
	 *
	 * Excel in es-CL writes `;`, because the comma is already the decimal
	 * separator in this locale — a comma-only reader mis-parses every file a
	 * Chilean clinic saves. Decided by counting on the FIRST line only: that
	 * line is headings, so a comma in it is a separator and not part of an
	 * address.
	 *
	 * ponytail: two candidates, not sniffing. A tab-separated file would be
	 * read as one column and refused for lacking the match column; add the
	 * tab here when somebody brings one.
	 */
	public static function delimiterOf(string $csv): string {
		$heading = strstr($csv, "\n", true);
		$heading = $heading === false ? $csv : $heading;

		return substr_count($heading, ';') > substr_count($heading, ',') ? ';' : ',';
	}

	/**
	 * Every line of the file, parsed — blank ones included; callers decide
	 * that a row holds no record.
	 *
	 * `fgetcsv` over a stream rather than `str_getcsv` per line, because a
	 * Notas cell somebody typed into a spreadsheet can contain a line break
	 * and only the stream reader knows that the quoted field has not ended.
	 *
	 * The empty `$escape` is the writers' own dialect: PHP's historical
	 * backslash escape is not in RFC 4180, is deprecated from 8.4, and would
	 * eat a backslash somebody typed in an address.
	 *
	 * @return list<list<?string>>
	 */
	public static function rows(string $csv): array {
		$handle = fopen('php://temp', 'r+');
		fwrite($handle, $csv);
		rewind($handle);

		$delimiter = self::delimiterOf($csv);
		$rows = [];
		while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
			$rows[] = $cells;
		}
		fclose($handle);

		return $rows;
	}

	/**
	 * One cell, with anything a spreadsheet would run defused.
	 *
	 * Excel and LibreOffice treat a cell beginning with `=`, `+`, `-`, `@`, a
	 * tab or a carriage return as a formula and evaluate it the moment the
	 * file is opened. Every user of these instances can edit every record, so
	 * "a colleague typed it" is not a defence: the formula runs on the machine
	 * of whoever opens the export, which is usually somebody else.
	 *
	 * A leading apostrophe is the spreadsheet's own "this is text" marker:
	 * Excel and LibreOffice do not display it, so the cell reads exactly as it
	 * was typed. It IS in the bytes, so a script reading this file sees it —
	 * which is the trade a spreadsheet export is for. A phone number beginning
	 * with `+56` gets the marker too. That is the price of not guessing which
	 * leading `+` is arithmetic.
	 */
	public static function text(mixed $value): string {
		$text = (string)$value;

		return $text !== '' && str_contains(self::FORMULA_TRIGGERS, $text[0]) ? "'" . $text : $text;
	}
}
