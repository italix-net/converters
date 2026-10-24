<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - Spans tests
 *
 * Profiles written by hand — no pixels, no samples: the same answers serve
 * an image's rows, a recording's windows, a video's frames.
 *
 * Run: php src/Libs/Italix/Converters/tests/SpansTest.php
 */

declare(strict_types=1);

(static function (): void {
    foreach ([
        __DIR__ . '/../../../../../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
        __DIR__ . '/../vendor/autoload.php',
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    fwrite(STDERR, "Could not find an autoloader. Run composer install.\n");
    exit(2);
})();

require_once __DIR__ . '/../ConversionException.php';
require_once __DIR__ . '/../Spans.php';

use Italix\Converters\ConversionException;
use Italix\Converters\Spans;

use function Italix\Testing\{suite, test, summary};

suite('Italix Converters — Spans');

$check = static function (string $label, mixed $actual, mixed $expected): void {
    if ($actual !== $expected) {
        test($label, false, sprintf('expected %s, got %s', var_export($expected, true), var_export($actual, true)));
        return;
    }
    test($label, true);
};

// -----------------------------------------------------------------------------
// content(): the first and last step with anything in it

$check('margins on both sides: content from 2 to 3', Spans::content([0.0, 0.0, 0.5, 1.0, 0.0]), [2, 3]);
$check('nothing at all: null', Spans::content([0.0, 0.0, 0.0]), null);
$check('an empty profile: null', Spans::content([]), null);
$check('noise at or under the threshold is nothing', Spans::content([0.001, 0.5, 0.002], 0.002), [1, 1]);
$check('content everywhere: all of it', Spans::content([1.0, 1.0]), [0, 1]);

// -----------------------------------------------------------------------------
// split(): runs of content between gaps at least min_gap long

$profile = [0.0, 1.0, 1.0, 0.0, 0.0, 1.0, 0.0, 1.0, 1.0, 0.0];
$check('min_gap 2: the 2-step gap splits, the 1-step one does not', Spans::split($profile, 2), [[1, 2], [5, 8]]);
$check('min_gap 1: every gap splits', Spans::split($profile, 1), [[1, 2], [5, 5], [7, 8]]);
$check('min_gap 3: no gap is long enough — one span, margins excluded', Spans::split($profile, 3), [[1, 8]]);
$check('all empty: no spans', Spans::split([0.0, 0.0], 1), []);
$check('all content: one span, end to end', Spans::split([1.0, 1.0, 1.0], 1), [[0, 2]]);
$check('the threshold applies here too', Spans::split([0.5, 0.001, 0.001, 0.5], 2, 0.002), [[0, 0], [3, 3]]);

$threw = false;
try {
    Spans::split($profile, 0);
} catch (ConversionException $e) {
    $threw = true;
}
$check('a gap of 0 steps throws', $threw, true);

exit(summary());
