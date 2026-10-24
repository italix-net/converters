<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - AxisLayout tests
 *
 * Worked examples computed by hand before the class was written, as for
 * CropGeometry — an independent expectation to check the code against.
 *
 * Run: php src/Libs/Italix/Converters/tests/AxisLayoutTest.php
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
require_once __DIR__ . '/../AxisLayout.php';

use Italix\Converters\AxisLayout;
use Italix\Converters\ConversionException;

use function Italix\Testing\{suite, test, summary};

suite('Italix Converters — AxisLayout');

$check = static function (string $label, mixed $actual, mixed $expected): void {
    if ($actual !== $expected) {
        test($label, false, sprintf('expected %s, got %s', var_export($expected, true), var_export($actual, true)));
        return;
    }
    test($label, true);
};

$throws = function (callable $call): bool {
    try {
        $call();
    } catch (ConversionException $e) {
        return true;
    }

    return false;
};

// -----------------------------------------------------------------------------
// counted tracks: 1024 / 3 does not divide — the leftover pixel goes to the first track

$check('1024px in 3 tracks: 342, 341, 341', AxisLayout::resolve(1024, 3, 0, 0, 0), [[0, 342], [342, 341], [683, 341]]);
$check('a digit string counts like an int', AxisLayout::resolve(1024, '3', 0, 0, 0), AxisLayout::resolve(1024, 3, 0, 0, 0));
$check('1000px, 3 tracks, gutter 10, margins 20+20: 940px shared as 314, 313, 313', AxisLayout::resolve(1000, 3, 10, 20, 20), [[20, 314], [344, 313], [667, 313]]);
$check('no spec is one track, between the margins', AxisLayout::resolve(500, null, 0, 10, 15), [[10, 475]]);
$check('5px in 2 shares: 3, 2 — the leftover to the first', AxisLayout::resolve(5, '1fr 1fr', 0, 0, 0), [[0, 3], [3, 2]]);

// -----------------------------------------------------------------------------
// track lists: pixels and shares

$check("'200px 1fr 2fr' on 800: 200 fixed, 600 shared 1:2", AxisLayout::resolve(800, '200px 1fr 2fr', 0, 0, 0), [[0, 200], [200, 200], [400, 400]]);
$check("'1.5fr 1fr' on 100: 60 and 40", AxisLayout::resolve(100, '1.5fr 1fr', 0, 0, 0), [[0, 60], [60, 40]]);
$check("'300px 200px 300px' on 800 adds up exactly", AxisLayout::resolve(800, '300px 200px 300px', 0, 0, 0), [[0, 300], [300, 200], [500, 300]]);
$check("'300px 200px 300px' on 801 does not add up, and there is no 'fr' to take the rest: throws", $throws(fn () => AxisLayout::resolve(801, '300px 200px 300px', 0, 0, 0)), true);
$check("'900px 1fr' on 800 needs more than there is: throws", $throws(fn () => AxisLayout::resolve(800, '900px 1fr', 0, 0, 0)), true);
$check("a bare number inside a list has no unit: throws", $throws(fn () => AxisLayout::resolve(800, '200 1fr', 0, 0, 0)), true);
$check("'100px' alone is one 100-pixel track, '100' alone is a hundred tracks", [AxisLayout::count('100px'), AxisLayout::count('100')], [1, 100]);
$check("a percentage is not a track ('1fr 3fr' says it): throws", $throws(fn () => AxisLayout::resolve(800, '25% 75%', 0, 0, 0)), true);
$check("'0fr' throws", $throws(fn () => AxisLayout::resolve(800, '0fr 1fr', 0, 0, 0)), true);
$check('an empty list throws', $throws(fn () => AxisLayout::resolve(800, '  ', 0, 0, 0)), true);
$check('a count of 0 throws', $throws(fn () => AxisLayout::resolve(800, 0, 0, 0, 0)), true);
$check('a count beyond MAX_TRACKS_N throws before anything is allocated', [$throws(fn () => AxisLayout::resolve(100, 100000000, 0, 0, 0)), $throws(fn () => AxisLayout::count('99999999999999999999'))], [true, true]);
$check('whitespace around a count is only whitespace', AxisLayout::count(' 3 '), 3);
$check('newlines between and after tokens are whitespace too, like spaces', [AxisLayout::count("1fr\n2fr\n"), AxisLayout::resolve(100, "100px\n", 0, 0, 0)], [2, [[0, 100]]]);
$check('10px, 3 tracks, gutter 5: no room left for the tracks, throws', $throws(fn () => AxisLayout::resolve(10, 3, 5, 0, 0)), true);
$check("'100px 1fr 1fr' on 101: 1px for two shares, one would be empty, throws", $throws(fn () => AxisLayout::resolve(101, '100px 1fr 1fr', 0, 0, 0)), true);
$check('count() of a list', AxisLayout::count('200px 1fr 2fr'), 3);
$check('count() of nothing is one track', AxisLayout::count(null), 1);

// -----------------------------------------------------------------------------
// lengths

$check("pixels('10%', 1000) = 100", AxisLayout::pixels('10%', 1000), 100);
$check("pixels('12.5%', 200) = 25", AxisLayout::pixels('12.5%', 200), 25);
$check('pixels(null) = 0: an absent margin is none', AxisLayout::pixels(null, 1000), 0);
$check('pixels(7) stays 7', AxisLayout::pixels(7, 1000), 7);
$check("pixels('7px') is 7 too", AxisLayout::pixels('7px', 1000), 7);
$check("pixels(\"10%\\n\") is not a percentage: throws", $throws(fn () => AxisLayout::pixels("10%\n", 1000)), true);
$check('a negative length throws', $throws(fn () => AxisLayout::pixels(-1, 1000)), true);
$check('a word throws', $throws(fn () => AxisLayout::pixels('wide', 1000)), true);

// -----------------------------------------------------------------------------
// fit(): the other direction — the pieces' sizes in, the axis out

$check('fit() of the three tracks resolve() gave for 1024 gives back 1024 and the same tracks', AxisLayout::fit([342, 341, 341], 3, 0, 0, 0), ['size' => 1024, 'tracks' => [[0, 342], [342, 341], [683, 341]]]);
$check("fit() with '120px 1fr', gutter 10, margins 5+5: the pixel track keeps 120, the share takes its piece's 50", AxisLayout::fit([100, 50], '120px 1fr', 10, 5, 5), ['size' => 190, 'tracks' => [[5, 120], [135, 50]]]);
$check('a piece larger than its pixel track throws — a merge never scales', $throws(fn () => AxisLayout::fit([130, 50], '120px 1fr', 0, 0, 0)), true);
$check('content for 1 track, a spec of 2: throws', $throws(fn () => AxisLayout::fit([10], 2, 0, 0, 0)), true);
$check('a shared track no piece went into is as large as the largest shared one — empty cells are background, not a collapsed line', AxisLayout::fit([40, 0], 2, 5, 0, 0), ['size' => 85, 'tracks' => [[0, 40], [45, 40]]]);
$check("fit() of '1fr 2fr' fits what it is given — it does not stretch a cell to honour the weights", AxisLayout::fit([100, 100], '1fr 2fr', 0, 0, 0), ['size' => 200, 'tracks' => [[0, 100], [100, 100]]]);
$check('a gutter so large the axis overflows an integer: throws, not a float', $throws(fn () => AxisLayout::fit([10, 10], 2, PHP_INT_MAX, 0, 0)), true);
$check('a negative gutter, margin or content: throws', [$throws(fn () => AxisLayout::resolve(100, 2, -1, 0, 0)), $throws(fn () => AxisLayout::resolve(100, 2, 0, -10, 0)), $throws(fn () => AxisLayout::fit([-5], null, 0, 0, 0))], [true, true, true]);

exit(summary());
