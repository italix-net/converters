<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - CropGeometry
 *
 * @package Italix\Converters
 */

declare(strict_types=1);

namespace Italix\Converters;

/**
 * Resolves ConversionOptions' crop-geometry keys (WIDTH, HEIGHT, RATIO,
 * ALIGN_X, ALIGN_Y, CROP_LEFT, CROP_RIGHT, CROP_TOP, CROP_BOTTOM) against a
 * source's real pixel dimensions into a concrete crop box. Never
 * instantiated — a stateless utility, not a value object a caller builds
 * and passes around: the caller's own public surface stays a flat set of
 * ConversionOptions keys, which is the whole reason this class exists
 * rather than a `RatioCrop` the caller would construct directly.
 *
 * **The per-axis rule.** X and Y are solved independently by the exact same
 * logic (`resolve_axis()`, called twice) because one equation ties three
 * quantities together on each axis: `crop_start + target_size + crop_end =
 * source_size`. That is two degrees of freedom, not three independent
 * knobs:
 *
 * - Any 2 of {target size, crop-start, crop-end} given → the third is
 *   solved directly; ALIGN is irrelevant, nothing is under-determined.
 * - Exactly 1 given: a target size alone needs ALIGN to decide where the
 *   cut falls (0=all off the start, 50=centered, 100=all off the end); a
 *   crop-start or crop-end alone leaves the other implicitly 0 (an
 *   asymmetric cut with a known single edge, not "the rest, centered").
 * - 0 given → no crop on that axis at all (target = full source size).
 * - All 3 given → must already agree, or it is a loud error, not a silent
 *   pick of one over the others.
 *
 * `CROP_LEFT`/`CROP_RIGHT`/`CROP_TOP`/`CROP_BOTTOM` accept a plain int
 * (pixels) or a string like `'10%'` (percentage of that axis's source
 * size) — resolved to pixels before the equation above ever runs, so both
 * forms compose identically.
 *
 * **RATIO fills in whichever axis has no direct information at all** —
 * WIDTH/CROP_LEFT/CROP_RIGHT absent on the X axis, or the HEIGHT/CROP_TOP/
 * CROP_BOTTOM equivalent on Y. When one axis has direct information and
 * the other does not, the resolved axis plus RATIO derives the other. When
 * neither axis has direct information, the axis that would otherwise need
 * to *grow* to hit the ratio is instead kept at its full source size, and
 * the other axis is cropped down to match — a ratio can only ever remove
 * content here, never add it. When *both* axes already have direct
 * information, RATIO is a consistency check (within 1px, for rounding),
 * not a silent override — a caller that specified an inconsistent
 * combination gets told so, not given whichever one this class happened
 * to prefer.
 *
 * A resolved crop that would need more of an axis than the source actually
 * has throws — this class only ever removes content, the same as every
 * `Trimmer`; it does not pad, letterbox, or upscale.
 */
final class CropGeometry
{
    private function __construct()
    {
    }

    /**
     * @return array{x: int, y: int, width: int, height: int} GD's own imagecrop() rectangle shape
     * @throws ConversionException the requested crop is inconsistent, or exceeds the source
     */
    public static function resolve(int $source_width_n, int $source_height_n, ConversionOptions $options, ?string $reader_c = null): array
    {
        $width_n       = $options->width_n($reader_c);
        $height_n      = $options->height_n($reader_c);
        $crop_left_n   = self::to_pixels($options->crop_left($reader_c), $source_width_n);
        $crop_right_n  = self::to_pixels($options->crop_right($reader_c), $source_width_n);
        $crop_top_n    = self::to_pixels($options->crop_top($reader_c), $source_height_n);
        $crop_bottom_n = self::to_pixels($options->crop_bottom($reader_c), $source_height_n);
        $align_x_n     = $options->align_x_n(50, $reader_c);
        $align_y_n     = $options->align_y_n(50, $reader_c);
        $ratio_c       = $options->ratio_code($reader_c);

        if ($ratio_c === null) {
            [$x, $target_width_n]  = self::resolve_axis($source_width_n, $width_n, $crop_left_n, $crop_right_n, $align_x_n);
            [$y, $target_height_n] = self::resolve_axis($source_height_n, $height_n, $crop_top_n, $crop_bottom_n, $align_y_n);

            return ['x' => $x, 'y' => $y, 'width' => $target_width_n, 'height' => $target_height_n];
        }

        [$ratio_width_n, $ratio_height_n] = self::parse_ratio($ratio_c);

        $has_x_info = $width_n !== null || $crop_left_n !== null || $crop_right_n !== null;
        $has_y_info = $height_n !== null || $crop_top_n !== null || $crop_bottom_n !== null;

        if ($has_x_info && !$has_y_info) {
            [$x, $target_width_n]  = self::resolve_axis($source_width_n, $width_n, $crop_left_n, $crop_right_n, $align_x_n);
            [$y, $target_height_n] = self::resolve_axis(
                $source_height_n,
                (int) round($target_width_n * $ratio_height_n / $ratio_width_n),
                null,
                null,
                $align_y_n
            );

            return ['x' => $x, 'y' => $y, 'width' => $target_width_n, 'height' => $target_height_n];
        }

        if ($has_y_info && !$has_x_info) {
            [$y, $target_height_n] = self::resolve_axis($source_height_n, $height_n, $crop_top_n, $crop_bottom_n, $align_y_n);
            [$x, $target_width_n]  = self::resolve_axis(
                $source_width_n,
                (int) round($target_height_n * $ratio_width_n / $ratio_height_n),
                null,
                null,
                $align_x_n
            );

            return ['x' => $x, 'y' => $y, 'width' => $target_width_n, 'height' => $target_height_n];
        }

        if (!$has_x_info && !$has_y_info) {
            $source_ratio_n = $source_width_n / $source_height_n;
            $target_ratio_n = $ratio_width_n / $ratio_height_n;

            if ($source_ratio_n > $target_ratio_n) {
                // Source is relatively wider than the target — height stays
                // full, width is the one that shrinks.
                [$y, $target_height_n] = self::resolve_axis($source_height_n, $source_height_n, null, null, $align_y_n);
                [$x, $target_width_n]  = self::resolve_axis(
                    $source_width_n,
                    (int) round($target_height_n * $ratio_width_n / $ratio_height_n),
                    null,
                    null,
                    $align_x_n
                );
            } else {
                // Source is relatively taller/narrower than the target —
                // width stays full, height shrinks. A target ratio *wider*
                // than the source falls here too: there is no content to
                // add, so the width cannot grow to match — only the height
                // can shrink to make the kept region relatively wider.
                [$x, $target_width_n]  = self::resolve_axis($source_width_n, $source_width_n, null, null, $align_x_n);
                [$y, $target_height_n] = self::resolve_axis(
                    $source_height_n,
                    (int) round($target_width_n * $ratio_height_n / $ratio_width_n),
                    null,
                    null,
                    $align_y_n
                );
            }

            return ['x' => $x, 'y' => $y, 'width' => $target_width_n, 'height' => $target_height_n];
        }

        // Both axes already fully specified — RATIO is a consistency check,
        // not a silent override of whichever axis this class prefers.
        [$x, $target_width_n]  = self::resolve_axis($source_width_n, $width_n, $crop_left_n, $crop_right_n, $align_x_n);
        [$y, $target_height_n] = self::resolve_axis($source_height_n, $height_n, $crop_top_n, $crop_bottom_n, $align_y_n);

        $expected_height_n = $target_width_n * $ratio_height_n / $ratio_width_n;

        if (abs($expected_height_n - $target_height_n) > 1) {
            throw new ConversionException(sprintf(
                'RATIO "%s" is inconsistent with the width/height this crop already resolved to (%dx%d).',
                $ratio_c,
                $target_width_n,
                $target_height_n
            ));
        }

        return ['x' => $x, 'y' => $y, 'width' => $target_width_n, 'height' => $target_height_n];
    }

    /**
     * @return array{0: int, 1: int} [crop_start, target_size] for one axis
     */
    private static function resolve_axis(
        int $source_size_n,
        ?int $target_size_n,
        ?int $crop_start_n,
        ?int $crop_end_n,
        int $align_percent_n
    ): array {
        $given_n = (int) ($target_size_n !== null) + (int) ($crop_start_n !== null) + (int) ($crop_end_n !== null);

        if ($given_n === 3) {
            if ($crop_start_n + $target_size_n + $crop_end_n !== $source_size_n) {
                throw new ConversionException(sprintf(
                    'Crop values are inconsistent on one axis: %d (start) + %d (size) + %d (end) = %d, not the source size %d.',
                    $crop_start_n,
                    $target_size_n,
                    $crop_end_n,
                    $crop_start_n + $target_size_n + $crop_end_n,
                    $source_size_n
                ));
            }
        } elseif ($given_n === 2) {
            if ($target_size_n === null) {
                $target_size_n = $source_size_n - $crop_start_n - $crop_end_n;
            } elseif ($crop_start_n === null) {
                $crop_start_n = $source_size_n - $target_size_n - $crop_end_n;
            } else {
                $crop_end_n = $source_size_n - $target_size_n - $crop_start_n;
            }
        } elseif ($given_n === 1 && $target_size_n !== null) {
            $total_cut_n  = $source_size_n - $target_size_n;
            $crop_start_n = (int) round($total_cut_n * $align_percent_n / 100);
            $crop_end_n   = $total_cut_n - $crop_start_n;
        } else {
            // 0 given, or only one of crop_start/crop_end given — the
            // other defaults to 0 (an asymmetric cut with one known edge),
            // not to "the rest, centered" — that is what specifying only a
            // target size, with ALIGN, is for.
            $crop_start_n ??= 0;
            $crop_end_n   ??= 0;
            $target_size_n = $source_size_n - $crop_start_n - $crop_end_n;
        }

        if ($target_size_n < 1 || $crop_start_n < 0 || $crop_end_n < 0) {
            throw new ConversionException(sprintf(
                'This crop removes more than the source has (%dpx): resolved target=%d, start=%d, end=%d.',
                $source_size_n,
                $target_size_n,
                $crop_start_n,
                $crop_end_n
            ));
        }

        return [$crop_start_n, $target_size_n];
    }

    private static function to_pixels(int|string|null $value, int $axis_source_size_n): ?int
    {
        if ($value === null || is_int($value)) {
            return $value;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)%$/', $value, $m) === 1) {
            return (int) round($axis_source_size_n * (float) $m[1] / 100);
        }

        throw new ConversionException("Not a valid crop amount: \"{$value}\" — expected an integer (pixels) or a percentage like \"10%\".");
    }

    /** @return array{0: float, 1: float} [ratio_width, ratio_height] */
    private static function parse_ratio(string $ratio_c): array
    {
        if (preg_match('/^(\d+(?:\.\d+)?):(\d+(?:\.\d+)?)$/', $ratio_c, $m) !== 1) {
            throw new ConversionException("Not a valid ratio: \"{$ratio_c}\" — expected \"W:H\", e.g. \"16:9\".");
        }

        $ratio_width_n  = (float) $m[1];
        $ratio_height_n = (float) $m[2];

        if ($ratio_width_n <= 0 || $ratio_height_n <= 0) {
            throw new ConversionException("A ratio's width and height must both be positive: \"{$ratio_c}\".");
        }

        return [$ratio_width_n, $ratio_height_n];
    }
}
