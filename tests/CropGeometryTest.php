<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - CropGeometry tests
 *
 * Every case here is one of the worked examples designed by hand before
 * this class was written — the point of writing them down first was to
 * have an independent expectation to check the code against, not to derive
 * the expectation from the code itself.
 *
 * Run: php src/Libs/Italix/Converters/tests/CropGeometryTest.php
 */

declare(strict_types=1);

require __DIR__ . '/../ConversionException.php';
require __DIR__ . '/../ConversionOptions.php';
require __DIR__ . '/../CropGeometry.php';

use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Converters\CropGeometry;

$failures = 0;
$checks_n = 0;

$check = function (string $label, mixed $actual, mixed $expected) use (&$failures, &$checks_n): void {
    $checks_n++;
    if ($actual !== $expected) {
        $failures++;
        printf("FAIL %s: expected %s, got %s\n", $label, var_export($expected, true), var_export($actual, true));
        return;
    }
    printf("ok   %s\n", $label);
};

// -----------------------------------------------------------------------------
// example 1: two crop values on one axis, source 1000x500 — the third (width) is solved directly

$box = CropGeometry::resolve(1000, 500, ConversionOptions::none()->with_crop_left(100)->with_crop_right(100));
$check('CROP_LEFT=100, CROP_RIGHT=100 on a 1000px axis solves width=800', $box['width'], 800);
$check('...x is exactly the crop_left value', $box['x'], 100);
$check('...the untouched Y axis stays the full source height', [$box['y'], $box['height']], [0, 500]);

// -----------------------------------------------------------------------------
// example 2: WIDTH alone, default ALIGN_X=50 — complementary to example 1, same result

$box2 = CropGeometry::resolve(1000, 500, ConversionOptions::none()->with_width(800));
$check('WIDTH=800 alone, centered, produces the identical box as CROP_LEFT=100+CROP_RIGHT=100', $box2, $box);

// -----------------------------------------------------------------------------
// example 3: WIDTH + an explicit, non-centered ALIGN_X

$box3 = CropGeometry::resolve(1000, 500, ConversionOptions::none()->with_width(800)->with_align_x(100));
$check('ALIGN_X=100 puts the entire 200px cut on the left, none on the right', [$box3['x'], $box3['width']], [200, 800]);

// -----------------------------------------------------------------------------
// example 4: one crop value + WIDTH — the other crop value is solved directly, ALIGN_X irrelevant

$box4 = CropGeometry::resolve(1000, 500, ConversionOptions::none()->with_crop_left(50)->with_width(800));
$check('CROP_LEFT=50 + WIDTH=800 solves crop_right=150 directly, ignoring the default ALIGN_X', $box4['x'], 50);
$check('...width is exactly what was asked', $box4['width'], 800);

// -----------------------------------------------------------------------------
// example 5: RATIO alone, neither axis has direct information — source 1000x1000, target 16:9 (wider than source)

$box5 = CropGeometry::resolve(1000, 1000, ConversionOptions::none()->with_ratio('16:9'));
$check('a target wider than the square source keeps the full width', [$box5['x'], $box5['width']], [0, 1000]);
$check('...and crops the height down to 16:9 of that width (563, centered)', [$box5['y'], $box5['height']], [219, 563]);

// The inverse: a target TALLER than the source (9:16) must instead crop width, keep full height.
$box5b = CropGeometry::resolve(1000, 1000, ConversionOptions::none()->with_ratio('9:16'));
$check('a target taller than the square source keeps the full height', [$box5b['y'], $box5b['height']], [0, 1000]);
$check('...and crops the width down to 9:16 of that height', [$box5b['x'], $box5b['width']], [219, 563]);

// -----------------------------------------------------------------------------
// example 6: RATIO + one axis already resolved — the other axis is derived from it, not from the source

$box6 = CropGeometry::resolve(1000, 1000, ConversionOptions::none()->with_ratio('16:9')->with_width(800));
$check('WIDTH=800 is honored exactly, not recomputed from the ratio', [$box6['x'], $box6['width']], [100, 800]);
$check('...height is derived from the RESOLVED width (800), not the source width (1000)', $box6['height'], (int) round(800 * 9 / 16));

// -----------------------------------------------------------------------------
// example 7: a crop that asks for more than the source has — fails loudly, not clamped or padded

$threw = false;
try {
    CropGeometry::resolve(1000, 500, ConversionOptions::none()->with_width(1200));
} catch (ConversionException $e) {
    $threw = true;
    $check('the message names the source size and what was resolved', str_contains($e->getMessage(), '1000') && str_contains($e->getMessage(), '1200'), true);
}
$check('WIDTH larger than the source throws rather than silently clamping or padding', $threw, true);

// -----------------------------------------------------------------------------
// example 8: percentage-based crop amounts resolve to the same pixels as the equivalent literal ints

$box8 = CropGeometry::resolve(1000, 500, ConversionOptions::none()->with_crop_left('10%'));
$check('CROP_LEFT="10%" of a 1000px axis resolves to the same 100px as a literal int', $box8['x'], 100);
$check('...and the rest of the axis (900px) becomes the target width, crop_right defaulting to 0', $box8['width'], 900);

// -----------------------------------------------------------------------------
// all three values given: consistent is accepted silently, inconsistent throws

$consistent = CropGeometry::resolve(1000, 500, ConversionOptions::none()->with_crop_left(100)->with_width(800)->with_crop_right(100));
$check('all three given, and consistent, is accepted', [$consistent['x'], $consistent['width']], [100, 800]);

$inconsistent_threw = false;
try {
    CropGeometry::resolve(1000, 500, ConversionOptions::none()->with_crop_left(100)->with_width(800)->with_crop_right(999));
} catch (ConversionException $e) {
    $inconsistent_threw = true;
}
$check('all three given, but inconsistent, throws rather than silently picking one', $inconsistent_threw, true);

// -----------------------------------------------------------------------------
// RATIO given alongside both axes already resolved: a consistency check, not a silent override

$ratio_consistent = CropGeometry::resolve(1600, 900, ConversionOptions::none()->with_ratio('16:9')->with_width(1600)->with_height(900));
$check('RATIO matching the already-resolved width/height is accepted', [$ratio_consistent['width'], $ratio_consistent['height']], [1600, 900]);

$ratio_inconsistent_threw = false;
try {
    CropGeometry::resolve(1600, 900, ConversionOptions::none()->with_ratio('16:9')->with_width(1600)->with_height(400));
} catch (ConversionException $e) {
    $ratio_inconsistent_threw = true;
}
$check('RATIO contradicting an already-resolved width/height throws, not silently overridden', $ratio_inconsistent_threw, true);

// -----------------------------------------------------------------------------
// no options at all: the identity crop — the full source, untouched

$identity = CropGeometry::resolve(640, 480, ConversionOptions::none());
$check('no crop-geometry options at all resolves to the untouched full frame', $identity, ['x' => 0, 'y' => 0, 'width' => 640, 'height' => 480]);

printf("\n%d checks, %d failures\n", $checks_n, $failures);
exit($failures === 0 ? 0 : 1);
