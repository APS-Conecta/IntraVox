<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\Harness;

use OCP\IL10N;

/**
 * IL10N as Nextcloud behaves with no translation loaded: the English source
 * string with its %s / %1$s / %n parameters filled in.
 *
 * Services and controllers that put user-facing messages in exceptions or
 * JSON bodies translate them, so a bare createMock(IL10N::class) would turn
 * every message into ''. `new EnglishL10n()` keeps message assertions
 * meaningful and works anywhere, without a trait or a TestCase.
 */
final class EnglishL10n implements IL10N {
    public function t(string $text, $parameters = []): string {
        return vsprintf($text, (array)$parameters);
    }

    public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string {
        $text = str_replace('%n', (string)$count, $count === 1 ? $text_singular : $text_plural);
        return vsprintf($text, $parameters);
    }

    public function l(string $type, $data, array $options = []) {
        return (string)$data;
    }
}
