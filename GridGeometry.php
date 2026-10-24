<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - GridGeometry
 *
 * @package Italix\Converters
 */

declare(strict_types=1);

namespace Italix\Converters;

/**
 * A sheet of pieces — a sprite sheet, a contact sheet, a strip of
 * screenshots, a 2×2 video mosaic — as boxes. Two AxisLayouts (columns
 * across, rows down) and a reading order; never instantiated, no pixels,
 * like CropGeometry. Reads ConversionOptions' grid keys: GRID_COLUMNS,
 * GRID_ROWS, GRID_GUTTER_X/Y, GRID_MARGIN_LEFT/RIGHT/TOP/BOTTOM, GRID_ORDER.
 *
 * cells() takes a sheet apart: its size in, one box per cell out. canvas()
 * puts one together: the pieces' sizes in, the sheet's size and one cell
 * per piece out. The same options both ways give the same boxes — the keys
 * describe the sheet, not the operation.
 *
 * Detected layouts ('auto') are not this class's business: detecting needs
 * the pixels, which only a driver has. A driver that detects hands the
 * result to sort() so it comes out in the same reading order as a declared
 * grid would.
 *
 * Rows and columns are numbered from 1, like PAGE.
 */
final class GridGeometry
{
    /** Reading orders: the first letter pair is the direction within a line, the second how lines follow each other. */
    public const ORDERS = ['lr-tb', 'rl-tb', 'tb-lr', 'tb-rl'];

    private function __construct()
    {
    }

    /**
     * Every cell of a <width_n> x <height_n> sheet, in reading order.
     *
     * @return list<array{row: int, column: int, x: int, y: int, width: int, height: int}>
     * @throws ConversionException a spec is malformed or 'auto', or the sheet has no room for it
     */
    public static function cells(int $width_n, int $height_n, ConversionOptions $options, ?string $reader_c = null): array
    {
        $columns_spec = self::declared($options->grid_columns($reader_c), 'GRID_COLUMNS');
        $rows_spec    = self::declared($options->grid_rows($reader_c), 'GRID_ROWS');

        $columns = AxisLayout::resolve(
            $width_n,
            $columns_spec,
            AxisLayout::pixels($options->grid_gutter_x($reader_c), $width_n),
            AxisLayout::pixels($options->grid_margin_left($reader_c), $width_n),
            AxisLayout::pixels($options->grid_margin_right($reader_c), $width_n)
        );
        $rows = AxisLayout::resolve(
            $height_n,
            $rows_spec,
            AxisLayout::pixels($options->grid_gutter_y($reader_c), $height_n),
            AxisLayout::pixels($options->grid_margin_top($reader_c), $height_n),
            AxisLayout::pixels($options->grid_margin_bottom($reader_c), $height_n)
        );

        $cells = [];
        foreach ($rows as $r => [$y, $row_height_n]) {
            foreach ($columns as $c => [$x, $column_width_n]) {
                $cells[] = ['row' => $r + 1, 'column' => $c + 1, 'x' => $x, 'y' => $y, 'width' => $column_width_n, 'height' => $row_height_n];
            }
        }

        return self::sort($cells, $options->grid_order_code('lr-tb', $reader_c));
    }

    /**
     * The sheet N pieces make, placed in reading order: its size, and the
     * cell each piece goes into (the piece itself may be smaller — where it
     * sits in its cell is ALIGN_X/ALIGN_Y's business, the driver's).
     *
     * How many columns and rows: GRID_COLUMNS and GRID_ROWS as given; one of
     * them alone, the other is as many as the pieces need; neither, one row
     * of N. A column is as wide as its widest piece, a row as tall as its
     * tallest, unless the spec gives it in pixels; a column or row no piece
     * reached (a declared grid that is not full) is as large as the largest
     * shared one — AxisLayout::fit(). Margins and gutters must be in pixels
     * here: a percentage of a sheet whose size is being worked out is
     * circular.
     *
     * @param  list<array{0: int, 1: int}> $pieces [width, height] of each piece, in the order they are placed
     * @return array{width: int, height: int, cells: list<array{row: int, column: int, x: int, y: int, width: int, height: int}>}
     * @throws ConversionException no pieces, too many for the grid, a percentage, 'auto', or a piece larger than its pixel track
     */
    public static function canvas(array $pieces, ConversionOptions $options, ?string $reader_c = null): array
    {
        $pieces_n = count($pieces);
        if ($pieces_n === 0) {
            throw new ConversionException('Nothing to place: no pieces.');
        }

        $columns_spec = self::declared($options->grid_columns($reader_c), 'GRID_COLUMNS');
        $rows_spec    = self::declared($options->grid_rows($reader_c), 'GRID_ROWS');

        if ($columns_spec === null && $rows_spec === null) {
            $columns_spec = $pieces_n;
        }
        $columns_n = $columns_spec === null ? null : AxisLayout::count($columns_spec);
        $rows_n    = $rows_spec === null ? null : AxisLayout::count($rows_spec);
        $columns_n ??= (int) ceil($pieces_n / $rows_n);
        $rows_n    ??= (int) ceil($pieces_n / $columns_n);
        $columns_spec ??= $columns_n;
        $rows_spec    ??= $rows_n;

        if ($columns_n * $rows_n < $pieces_n) {
            throw new ConversionException("{$pieces_n} pieces do not fit a grid of {$columns_n} x {$rows_n}.");
        }

        // Where each piece goes: the reading order of the whole grid, first N cells.
        $slots = [];
        for ($r = 1; $r <= $rows_n; $r++) {
            for ($c = 1; $c <= $columns_n; $c++) {
                $slots[] = ['row' => $r, 'column' => $c];
            }
        }
        $slots = array_slice(self::sort($slots, $options->grid_order_code('lr-tb', $reader_c)), 0, $pieces_n);

        $widest  = array_fill(0, $columns_n, 0);
        $tallest = array_fill(0, $rows_n, 0);
        foreach ($slots as $i => ['row' => $r, 'column' => $c]) {
            $widest[$c - 1]  = max($widest[$c - 1], $pieces[$i][0]);
            $tallest[$r - 1] = max($tallest[$r - 1], $pieces[$i][1]);
        }

        $x = AxisLayout::fit(
            $widest,
            $columns_spec,
            self::fixed($options->grid_gutter_x($reader_c), 'GRID_GUTTER_X'),
            self::fixed($options->grid_margin_left($reader_c), 'GRID_MARGIN_LEFT'),
            self::fixed($options->grid_margin_right($reader_c), 'GRID_MARGIN_RIGHT')
        );
        $y = AxisLayout::fit(
            $tallest,
            $rows_spec,
            self::fixed($options->grid_gutter_y($reader_c), 'GRID_GUTTER_Y'),
            self::fixed($options->grid_margin_top($reader_c), 'GRID_MARGIN_TOP'),
            self::fixed($options->grid_margin_bottom($reader_c), 'GRID_MARGIN_BOTTOM')
        );

        $cells = [];
        foreach ($slots as ['row' => $r, 'column' => $c]) {
            [$cell_x, $cell_width_n]  = $x['tracks'][$c - 1];
            [$cell_y, $cell_height_n] = $y['tracks'][$r - 1];
            $cells[] = ['row' => $r, 'column' => $c, 'x' => $cell_x, 'y' => $cell_y, 'width' => $cell_width_n, 'height' => $cell_height_n];
        }

        return ['width' => $x['size'], 'height' => $y['size'], 'cells' => $cells];
    }

    /**
     * Cells in a reading order: 'lr-tb' left to right then top to bottom (the
     * default), 'rl-tb' right to left (a manga page), 'tb-lr' top to bottom
     * then left to right (a newspaper's columns), 'tb-rl'. Works on any set of
     * row/column labels, regular or not — a detected layout whose rows hold
     * different numbers of pieces sorts the same way.
     *
     * @template T of array{row: int, column: int}
     * @param  list<T> $cells
     * @return list<T>
     * @throws ConversionException an unknown order
     */
    public static function sort(array $cells, string $order_c): array
    {
        if (!in_array($order_c, self::ORDERS, true)) {
            throw new ConversionException("Not a reading order: \"{$order_c}\" — one of " . implode(', ', self::ORDERS) . '.');
        }

        usort($cells, static fn (array $a, array $b): int => match ($order_c) {
            'lr-tb' => [$a['row'], $a['column']] <=> [$b['row'], $b['column']],
            'rl-tb' => [$a['row'], $b['column']] <=> [$b['row'], $a['column']],
            'tb-lr' => [$a['column'], $a['row']] <=> [$b['column'], $b['row']],
            'tb-rl' => [$b['column'], $a['row']] <=> [$a['column'], $b['row']],
        });

        return $cells;
    }

    /** @throws ConversionException on 'auto', which only a detecting driver can resolve */
    private static function declared(int|string|null $spec, string $key_c): int|string|null
    {
        if ($spec === 'auto') {
            throw new ConversionException("{$key_c} = 'auto' is detection — a driver's job, on the pixels; GridGeometry only lays out a declared grid.");
        }

        return $spec;
    }

    /** @throws ConversionException on a percentage, which has nothing to be a percentage of while the sheet's size is being worked out */
    private static function fixed(int|string|null $amount, string $key_c): int
    {
        if (is_string($amount) && str_ends_with(trim($amount), '%')) {
            throw new ConversionException("{$key_c} = \"{$amount}\": putting a sheet together needs pixels — a percentage of the size being worked out is circular.");
        }

        return AxisLayout::pixels($amount, 0);
    }
}
