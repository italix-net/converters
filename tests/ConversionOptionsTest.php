<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - ConversionOptions readers, by their 0.7.0 names
 *
 * quality(), page_i(), align_x_perc(), align_y_perc(), gap_tolerance_perc()
 * — and the names published earlier, quality_n() and page_n() (0.3.0),
 * align_x_n() and align_y_n() (0.5.0), still answering the same until 0.8.0.
 *
 * Run: php src/Libs/Italix/Converters/tests/ConversionOptionsTest.php
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

require_once __DIR__ . '/../ConversionOptions.php';

use Italix\Converters\ConversionOptions;

use function Italix\Testing\{suite, test, summary};

suite('Italix Converters — ConversionOptions');

$check = static function (string $label, mixed $actual, mixed $expected): void {
    if ($actual !== $expected) {
        test($label, false, sprintf('expected %s, got %s', var_export($expected, true), var_export($actual, true)));
        return;
    }
    test($label, true);
};

$options = ConversionOptions::none()
    ->with_quality(70)
    ->with_page(3)
    ->with_align_x(0)
    ->with_align_y(100)
    ->with_gap_tolerance(4);

// -----------------------------------------------------------------------------
// the readers, by their names

$check('quality()', $options->quality(), 70);
$check('page_i()', $options->page_i(), 3);
$check('align_x_perc()', $options->align_x_perc(), 0);
$check('align_y_perc()', $options->align_y_perc(), 100);
$check('gap_tolerance_perc()', $options->gap_tolerance_perc(), 4);

// -----------------------------------------------------------------------------
// their defaults, when nothing was set

$none = ConversionOptions::none();
$check('quality() with nothing set: the caller\'s default', $none->quality(90), 90);
$check('page_i() with nothing set: page 1', $none->page_i(), 1);
$check('align_x_perc()/align_y_perc() with nothing set: 50, centred', [$none->align_x_perc(), $none->align_y_perc()], [50, 50]);
$check('gap_tolerance_perc() with nothing set: 10', $none->gap_tolerance_perc(), 10);

// -----------------------------------------------------------------------------
// scoped to a driver, as every reader is

$scoped = ConversionOptions::none()->with_quality(40)->with_quality(95, 'Some\\Driver')->with_page(2, 'Some\\Driver');
$check('quality() naming the driver: its scoped value wins', $scoped->quality(null, 'Some\\Driver'), 95);
$check('quality() naming another: the unscoped one', $scoped->quality(null, 'Other\\Driver'), 40);
$check('page_i() naming the driver', $scoped->page_i(1, 'Some\\Driver'), 2);

// -----------------------------------------------------------------------------
// the names 0.3.0 published, deprecated, still the same answers

$check('quality_n() answers as quality()', [$options->quality_n(), $none->quality_n(90), $scoped->quality_n(null, 'Some\\Driver')], [70, 90, 95]);
$check('page_n() answers as page_i()', [$options->page_n(), $none->page_n(), $scoped->page_n(1, 'Some\\Driver')], [3, 1, 2]);

// ... and the two 0.5.0 published
$aligned = ConversionOptions::none()->with_align_x(20)->with_align_y(80)->with_align_x(0, 'Some\\Driver');
$check('align_x_n() answers as align_x_perc()', [$aligned->align_x_n(), $none->align_x_n(), $aligned->align_x_n(50, 'Some\\Driver')], [20, 50, 0]);
$check('align_y_n() answers as align_y_perc()', [$aligned->align_y_n(), $none->align_y_n(33)], [80, 33]);

exit(summary());
