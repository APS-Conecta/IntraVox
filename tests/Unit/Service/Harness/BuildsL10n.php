<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\Harness;

use OCP\IL10N;

/**
 * Trait access to EnglishL10n, for tests that already compose harness traits.
 */
trait BuildsL10n {
    protected function englishL10n(): IL10N {
        return new EnglishL10n();
    }
}
