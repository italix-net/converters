<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - GridGeometry tests
 *
 * Worked examples computed by hand before the class was written. The last
 * section is the property the whole design rests on: canvas() with the
 * options cells() used gives back the same sheet.
 *
 * Run: php src/Libs/Italix/Converters/tests/GridGeometryTest.php
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
require_once __DIR__ . '/../ConversionOptions.php';
require_once __DIR__ . '/../ConvertedDocument.php';
require_once __DIR__ . '/../AxisLayout.php';
require_once __DIR__ . '/../GridGeometry.php';

use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Converters\ConvertedDocument;
use Italix\Converters\GridGeometry;

use function Italix\Testing\{suite, test, summary};

suite('Italix Converters — GridGeometry');

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

$labels = static fn (array $cells): array => array_map(static fn (array $c): string => "{$c['row']},{$c['column']}", $cells);

// -----------------------------------------------------------------------------
// cells(): a 300x200 sheet, 3 columns, 2 rows, no gutter

$three_by_two = ConversionOptions::none()->with_grid_columns(3)->with_grid_rows(2);
$cells        = GridGeometry::cells(300, 200, $three_by_two);
$check('six cells', count($cells), 6);
$check('the first is the top-left 100x100', $cells[0], ['row' => 1, 'column' => 1, 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100]);
$check('the fourth starts the second row', $cells[3], ['row' => 2, 'column' => 1, 'x' => 0, 'y' => 100, 'width' => 100, 'height' => 100]);
$check("'lr-tb' (the default) reads rows left to right", $labels($cells), ['1,1', '1,2', '1,3', '2,1', '2,2', '2,3']);
$check("'rl-tb' reads rows right to left", $labels(GridGeometry::cells(300, 200, $three_by_two->with_grid_order('rl-tb'))), ['1,3', '1,2', '1,1', '2,3', '2,2', '2,1']);
$check("'tb-lr' reads columns top to bottom", $labels(GridGeometry::cells(300, 200, $three_by_two->with_grid_order('tb-lr'))), ['1,1', '2,1', '1,2', '2,2', '1,3', '2,3']);
$check("'tb-rl' reads columns top to bottom, right to left", $labels(GridGeometry::cells(300, 200, $three_by_two->with_grid_order('tb-rl'))), ['1,3', '2,3', '1,2', '2,2', '1,1', '2,1']);

// -----------------------------------------------------------------------------
// margins and gutters in percent: 1000x500, 2 columns, margins 10% left and right, gutter 20

$framed = GridGeometry::cells(1000, 500, ConversionOptions::none()
    ->with_grid_columns(2)
    ->with_grid_margin_left('10%')
    ->with_grid_margin_right('10%')
    ->with_grid_gutter_x(20)
    ->with_grid_margin_top('10%'));
$check('780px shared by 2 columns: 390 each, the first after the 100px margin', [$framed[0]['x'], $framed[0]['width']], [100, 390]);
$check('...the second after the gutter', [$framed[1]['x'], $framed[1]['width']], [510, 390]);
$check('...one row, below a 10% top margin', [$framed[0]['y'], $framed[0]['height']], [50, 450]);

// -----------------------------------------------------------------------------
// one row, one column: slices

$strip = GridGeometry::cells(1080, 1920, ConversionOptions::none()->with_grid_rows(4));
$check('a vertical strip of 4 rows: 4 cells, each the full width, 480 high', array_map(static fn (array $c): array => [$c['x'], $c['y'], $c['width'], $c['height']], $strip), [[0, 0, 1080, 480], [0, 480, 1080, 480], [0, 960, 1080, 480], [0, 1440, 1080, 480]]);
$check('no grid keys at all: the whole sheet is one cell', GridGeometry::cells(640, 480, ConversionOptions::none()), [['row' => 1, 'column' => 1, 'x' => 0, 'y' => 0, 'width' => 640, 'height' => 480]]);

$check("'auto' is a driver's job: throws", $throws(fn () => GridGeometry::cells(300, 200, ConversionOptions::none()->with_grid_columns('auto'))), true);
$check('an unknown reading order throws', $throws(fn () => GridGeometry::cells(300, 200, $three_by_two->with_grid_order('bt-lr'))), true);

// -----------------------------------------------------------------------------
// canvas(): five pieces into 3 columns — 2 rows, each column as wide as its widest piece

$pieces = [[100, 80], [120, 60], [100, 80], [100, 80], [100, 80]];
$sheet  = GridGeometry::canvas($pieces, ConversionOptions::none()->with_grid_columns(3));
$check('5 pieces, 3 columns: a 320x160 sheet (the middle column 120 wide)', [$sheet['width'], $sheet['height']], [320, 160]);
$check('the second piece goes into row 1, column 2, a 120x80 cell at x=100', $sheet['cells'][1], ['row' => 1, 'column' => 2, 'x' => 100, 'y' => 0, 'width' => 120, 'height' => 80]);
$check('the fifth into row 2, column 2', [$sheet['cells'][4]['row'], $sheet['cells'][4]['column']], [2, 2]);
$check("'tb-lr' with 2 columns and 3 pieces: 2 rows, the third piece tops the second column", $labels(GridGeometry::canvas([[10, 10], [10, 10], [10, 10]], ConversionOptions::none()->with_grid_columns(2)->with_grid_order('tb-lr'))['cells']), ['1,1', '2,1', '1,2']);
$check('no grid keys: one row of N, as tall as the tallest', array_slice(GridGeometry::canvas([[10, 10], [20, 5], [30, 7]], ConversionOptions::none()), 0, 2), ['width' => 60, 'height' => 10]);
$check('GRID_ROWS alone: 3 pieces in 2 rows need 2 columns', GridGeometry::canvas([[10, 10], [10, 10], [10, 10]], ConversionOptions::none()->with_grid_rows(2))['width'], 20);
$check('5 pieces do not fit 2x2: throws', $throws(fn () => GridGeometry::canvas(array_fill(0, 5, [10, 10]), ConversionOptions::none()->with_grid_columns(2)->with_grid_rows(2))), true);
$check('a percentage margin while the size is being worked out: throws', $throws(fn () => GridGeometry::canvas([[10, 10]], ConversionOptions::none()->with_grid_margin_left('10%'))), true);
$check('no pieces: throws', $throws(fn () => GridGeometry::canvas([], ConversionOptions::none())), true);
$check("a margin in '16px' is pixels, fine here", GridGeometry::canvas([[10, 10]], ConversionOptions::none()->with_grid_margin_left('16px'))['width'], 26);

$two_in_six = ConversionOptions::none()->with_grid_columns(3)->with_grid_rows(2)->with_grid_gutter_x(4);
$sparse     = GridGeometry::canvas([[10, 10], [10, 10]], $two_in_six);
$check('2 pieces in a declared 3x2 grid: the empty cells are background of the grid\'s own size, 38x20', [$sparse['width'], $sparse['height']], [38, 20]);
$check('...and that sheet lays out again with the same options, the pieces in the same cells', array_slice(GridGeometry::cells(38, 20, $two_in_six), 0, 2), $sparse['cells']);

// -----------------------------------------------------------------------------
// the round trip: canvas() with the options cells() used gives back the same sheet and the same boxes

$sheet_options = ConversionOptions::none()
    ->with_grid_columns(3)
    ->with_grid_rows(2)
    ->with_grid_gutter_x(8)
    ->with_grid_gutter_y(8)
    ->with_grid_margin_left(16)
    ->with_grid_margin_right(16)
    ->with_grid_margin_top(16)
    ->with_grid_margin_bottom(16);
$apart    = GridGeometry::cells(1024, 600, $sheet_options);
$together = GridGeometry::canvas(array_map(static fn (array $c): array => [$c['width'], $c['height']], $apart), $sheet_options);
$check('1024x600, 3x2, gutter 8, margins 16: the columns are 326, 325, 325', array_column(array_slice($apart, 0, 3), 'width'), [326, 325, 325]);
$check('put back together: the same 1024x600', [$together['width'], $together['height']], [1024, 600]);
$check('...and every cell in the same place', $together['cells'], $apart);

$flipped = $sheet_options->with_grid_order('tb-rl');
$check("the round trip holds in 'tb-rl' order too", GridGeometry::canvas(array_map(static fn (array $c): array => [$c['width'], $c['height']], GridGeometry::cells(1024, 600, $flipped)), $flipped)['cells'], GridGeometry::cells(1024, 600, $flipped));

// -----------------------------------------------------------------------------
// a piece says where it came from

$piece = new ConvertedDocument('bytes', 'png', 'image/png', ['row' => 2, 'column' => 1, 'x' => 0, 'y' => 100, 'width' => 100, 'height' => 100]);
$check('a piece carries its locator', $piece->locator(), ['row' => 2, 'column' => 1, 'x' => 0, 'y' => 100, 'width' => 100, 'height' => 100]);
$check('anything else has none — the constructor is unchanged for every existing caller', (new ConvertedDocument('bytes', 'png', 'image/png'))->locator(), null);

exit(summary());
