<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - ConversionOptions
 *
 * @package Italix\Converters
 */

declare(strict_types=1);

namespace Italix\Converters;

/**
 * A bag of per-call knobs, passed alongside (source, from, to) to every
 * driver on a route — the same object reaches every hop unchanged.
 *
 * Two surfaces on purpose. A handful of keys recur across drivers that
 * otherwise share nothing (a JPEG encoder and a WebP encoder both have a
 * "quality"; a paginated source format and a paginated target format both
 * have a "page") — those get a named constant and a typed with_*()/*_n()
 * pair, so two drivers agree on the same spelling instead of one calling it
 * `quality` and another `q`. Everything else — a driver-specific knob this
 * class was never told about — goes through the generic with()/get(),
 * which is why this bag never needs to grow a new method for a new format
 * family. GdImageConverter reads WIDTH/HEIGHT/QUALITY; PdfPageToImageConverter
 * reads PAGE; neither reads a key it doesn't recognize, and both simply
 * ignore keys meant for other drivers on the same route.
 *
 * COLORSPACE and ICC_PROFILE are declared here even though no driver in
 * this codebase can honor them yet — ext-gd has no CMYK support at all, and
 * dompdf's PDF output is RGB-only. The vocabulary exists so a driver that
 * *can* (built later, on top of Imagick with a real embedded ICC profile,
 * the only way to get correct — not just present — CMYK) has a name to
 * read from, and so that a driver asked for a colorspace it cannot produce
 * has something concrete to check and refuse: see
 * UnsupportedOptionException. A silently-ignored colorspace request is a
 * correctness bug wearing a passing test; a loud one is just a bug.
 *
 * Immutable, like everything else with a "with…()" method in this library
 * (ConvertedDocument, and every Php\Types\* wrapper in italix/type-factory
 * follow the same shape) — with() and the with_*() convenience methods all
 * return a new instance, the receiver is never touched.
 *
 * **Driver-scoped values**: every with_*() accepts an optional trailing
 * driver identifier (a class name), and every read accepts an optional
 * trailing reader identifier. Unscoped (the common case) behaves exactly as
 * before — a value set with no driver name reaches every hop, and every
 * driver that recognizes the key uses it. A value set *with* a driver name
 * is invisible to every other reader; only a driver that names itself on
 * the read sees it. This exists for the case unscoped values cannot handle:
 * two different drivers that happen to read the same key name with
 * different meanings (GdImageConverter's QUALITY is a 0-100 JPEG/WebP
 * encoder setting; a hypothetical AI-upscale driver's QUALITY could mean a
 * 1-5 fidelity preset) — scoping one to a specific driver removes the
 * collision without either driver needing a differently-spelled key.
 * Deliberately *not* a position-based ("this specific hop") override: a
 * driver can appear more than once across separately-run operations (never
 * twice in one ConverterSet route, since route() never revisits a format —
 * but always possible across two direct convert() calls), and identity is
 * the thing that stays meaningful in both cases, where a hop position would
 * not.
 */
final class ConversionOptions
{
    public const QUALITY     = 'quality';      // int 0-100
    public const LOSSLESS    = 'lossless';     // bool
    public const PAGE        = 'page';         // int, 1-based
    public const WIDTH       = 'width';        // int, pixels
    public const HEIGHT      = 'height';       // int, pixels
    public const COLORSPACE  = 'colorspace';   // string: 'rgb' | 'cmyk' | 'gray' | ...
    public const ICC_PROFILE = 'icc_profile';  // string, raw profile bytes

    public const COLORSPACE_RGB  = 'rgb';
    public const COLORSPACE_CMYK = 'cmyk';
    public const COLORSPACE_GRAY = 'gray';

    // Crop geometry — read by Italix\Converters\CropGeometry::resolve(), shared
    // across every Trimmer that crops a 2-D frame (a still image, or a
    // video's per-frame geometry — as opposed to a temporal trim, which is
    // a different axis entirely). See CropGeometry's own docblock for how
    // these compose: any 2 of {WIDTH, CROP_LEFT, CROP_RIGHT} on one axis
    // determine the third; 1 alone needs ALIGN_X to decide where the cut
    // falls; RATIO fills in whichever axis has no direct information at all.
    public const RATIO       = 'ratio';        // string "W:H", e.g. '16:9'
    public const ALIGN_X     = 'align_x';      // int 0-100 (0=left, 100=right) — consulted only when the X axis is under-determined
    public const ALIGN_Y     = 'align_y';      // int 0-100 (0=top, 100=bottom) — consulted only when the Y axis is under-determined
    public const CROP_LEFT   = 'crop_left';    // int (pixels) or a string like '10%' (percentage of source width)
    public const CROP_RIGHT  = 'crop_right';   // int or '%', of source width
    public const CROP_TOP    = 'crop_top';     // int or '%', of source height
    public const CROP_BOTTOM = 'crop_bottom';  // int or '%', of source height

    // Grid layout — read by Italix\Converters\GridGeometry (and AxisLayout
    // under it), shared by an Extractor that takes a sheet apart and a
    // Merger that puts one together: the keys describe the sheet, not the
    // operation, so the same options do both. On each axis:
    // margin_start + track + gutter + track + ... + margin_end = size.
    public const GRID_COLUMNS       = 'grid_columns';        // int (that many equal tracks), a track list like '200px 1fr 2fr' (pixels and shares), or 'auto' (detect)
    public const GRID_ROWS          = 'grid_rows';           // same, on the Y axis
    public const GRID_GUTTER_X      = 'grid_gutter_x';       // int (pixels) or '%' of the sheet's width — between columns
    public const GRID_GUTTER_Y      = 'grid_gutter_y';       // int or '%', of the sheet's height — between rows
    public const GRID_MARGIN_LEFT   = 'grid_margin_left';    // int or '%', of the sheet's width — the frame around the grid
    public const GRID_MARGIN_RIGHT  = 'grid_margin_right';   // int or '%', of the sheet's width
    public const GRID_MARGIN_TOP    = 'grid_margin_top';     // int or '%', of the sheet's height
    public const GRID_MARGIN_BOTTOM = 'grid_margin_bottom';  // int or '%', of the sheet's height
    public const GRID_ORDER         = 'grid_order';          // 'lr-tb' (default), 'rl-tb', 'tb-lr', 'tb-rl' — the reading order of the pieces
    public const GRID_EXPECT        = 'grid_expect';         // int — how many pieces there must be; any other count is an error, not a wrong cut
    public const GRID_SKIP_EMPTY    = 'grid_skip_empty';     // bool — a cell that is all background is not returned

    // Background and gap detection — what fills a gutter or a margin when
    // merging, and what counts as "nothing" when detecting one: shared by a
    // grid Extractor and an adaptive Trimmer (Italix\Converters\Spans).
    public const BACKGROUND    = 'background';     // string '#rrggbb', '#rrggbbaa' or 'transparent'; absent = detected (the commonest colour along the border)
    public const GAP_MIN       = 'gap_min';        // int (pixels) or '%' of the axis — the thinnest run of background that counts as a gutter
    public const GAP_TOLERANCE = 'gap_tolerance';  // int 0-100 — how far from the background a pixel may be and still count as background

    /** @var array<string, mixed> */
    private array $values;

    /** @var array<string, array<string, mixed>> key => [driver class name => value] */
    private array $scoped_values;

    /**
     * @param array<string, mixed> $values
     * @param array<string, array<string, mixed>> $scoped_values
     */
    private function __construct(array $values, array $scoped_values = [])
    {
        $this->values        = $values;
        $this->scoped_values = $scoped_values;
    }

    /** The common case: no per-call knobs at all. */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Escape hatch in the other direction — build from a raw array when
     * that is what a caller already has (config parsed from JSON, for
     * instance), rather than chaining with() once per key. Unscoped only —
     * a scoped value can only come from with(), since a raw array has no
     * way to express "and only driver X sees this one".
     *
     * @param array<string, mixed> $values
     */
    public static function from(array $values): self
    {
        return new self($values);
    }

    /**
     * A new instance with one key set, generic — for a key this class was
     * never told about. $driver_c null (the default) sets the unscoped
     * value everyone sees; given, the value is visible only to a read that
     * names that same driver.
     */
    public function with(string $key, mixed $value, ?string $driver_c = null): self
    {
        if ($driver_c === null) {
            return new self([...$this->values, $key => $value], $this->scoped_values);
        }

        $scoped                     = $this->scoped_values;
        $scoped[$key][$driver_c]    = $value;

        return new self($this->values, $scoped);
    }

    public function with_quality(int $quality, ?string $driver_c = null): self
    {
        return $this->with(self::QUALITY, $quality, $driver_c);
    }

    public function with_lossless(bool $flag, ?string $driver_c = null): self
    {
        return $this->with(self::LOSSLESS, $flag, $driver_c);
    }

    public function with_page(int $page_i, ?string $driver_c = null): self
    {
        return $this->with(self::PAGE, $page_i, $driver_c);
    }

    public function with_width(int $width_n, ?string $driver_c = null): self
    {
        return $this->with(self::WIDTH, $width_n, $driver_c);
    }

    public function with_height(int $height_n, ?string $driver_c = null): self
    {
        return $this->with(self::HEIGHT, $height_n, $driver_c);
    }

    public function with_colorspace(string $colorspace_c, ?string $driver_c = null): self
    {
        return $this->with(self::COLORSPACE, $colorspace_c, $driver_c);
    }

    public function with_icc_profile(string $bytes, ?string $driver_c = null): self
    {
        return $this->with(self::ICC_PROFILE, $bytes, $driver_c);
    }

    public function with_ratio(string $ratio_c, ?string $driver_c = null): self
    {
        return $this->with(self::RATIO, $ratio_c, $driver_c);
    }

    public function with_align_x(int $align_perc, ?string $driver_c = null): self
    {
        return $this->with(self::ALIGN_X, $align_perc, $driver_c);
    }

    public function with_align_y(int $align_perc, ?string $driver_c = null): self
    {
        return $this->with(self::ALIGN_Y, $align_perc, $driver_c);
    }

    public function with_crop_left(int|string $amount, ?string $driver_c = null): self
    {
        return $this->with(self::CROP_LEFT, $amount, $driver_c);
    }

    public function with_crop_right(int|string $amount, ?string $driver_c = null): self
    {
        return $this->with(self::CROP_RIGHT, $amount, $driver_c);
    }

    public function with_crop_top(int|string $amount, ?string $driver_c = null): self
    {
        return $this->with(self::CROP_TOP, $amount, $driver_c);
    }

    public function with_crop_bottom(int|string $amount, ?string $driver_c = null): self
    {
        return $this->with(self::CROP_BOTTOM, $amount, $driver_c);
    }

    public function with_grid_columns(int|string $tracks, ?string $driver_c = null): self
    {
        return $this->with(self::GRID_COLUMNS, $tracks, $driver_c);
    }

    public function with_grid_rows(int|string $tracks, ?string $driver_c = null): self
    {
        return $this->with(self::GRID_ROWS, $tracks, $driver_c);
    }

    public function with_grid_gutter_x(int|string $amount, ?string $driver_c = null): self
    {
        return $this->with(self::GRID_GUTTER_X, $amount, $driver_c);
    }

    public function with_grid_gutter_y(int|string $amount, ?string $driver_c = null): self
    {
        return $this->with(self::GRID_GUTTER_Y, $amount, $driver_c);
    }

    public function with_grid_margin_left(int|string $amount, ?string $driver_c = null): self
    {
        return $this->with(self::GRID_MARGIN_LEFT, $amount, $driver_c);
    }

    public function with_grid_margin_right(int|string $amount, ?string $driver_c = null): self
    {
        return $this->with(self::GRID_MARGIN_RIGHT, $amount, $driver_c);
    }

    public function with_grid_margin_top(int|string $amount, ?string $driver_c = null): self
    {
        return $this->with(self::GRID_MARGIN_TOP, $amount, $driver_c);
    }

    public function with_grid_margin_bottom(int|string $amount, ?string $driver_c = null): self
    {
        return $this->with(self::GRID_MARGIN_BOTTOM, $amount, $driver_c);
    }

    public function with_grid_order(string $order_c, ?string $driver_c = null): self
    {
        return $this->with(self::GRID_ORDER, $order_c, $driver_c);
    }

    public function with_grid_expect(int $pieces_n, ?string $driver_c = null): self
    {
        return $this->with(self::GRID_EXPECT, $pieces_n, $driver_c);
    }

    public function with_grid_skip_empty(bool $flag, ?string $driver_c = null): self
    {
        return $this->with(self::GRID_SKIP_EMPTY, $flag, $driver_c);
    }

    public function with_background(string $color_c, ?string $driver_c = null): self
    {
        return $this->with(self::BACKGROUND, $color_c, $driver_c);
    }

    public function with_gap_min(int|string $amount, ?string $driver_c = null): self
    {
        return $this->with(self::GAP_MIN, $amount, $driver_c);
    }

    public function with_gap_tolerance(int $tolerance_perc, ?string $driver_c = null): self
    {
        return $this->with(self::GAP_TOLERANCE, $tolerance_perc, $driver_c);
    }

    /** True if get() (naming the same $reader_c, when given) would return something other than a caller-supplied default. */
    public function has(string $key, ?string $reader_c = null): bool
    {
        if ($reader_c !== null && array_key_exists($key, $this->scoped_values) && array_key_exists($reader_c, $this->scoped_values[$key])) {
            return true;
        }

        return array_key_exists($key, $this->values);
    }

    /**
     * Generic read — for a key this class was never told about. $reader_c
     * is the reading driver's own identity (typically static::class); when
     * a scoped value exists under that exact identity, it wins over the
     * unscoped value. Omitting $reader_c (or a driver that never scoped
     * anything under this key) reads the unscoped value, exactly as before.
     */
    public function get(string $key, mixed $default = null, ?string $reader_c = null): mixed
    {
        if ($reader_c !== null && isset($this->scoped_values[$key][$reader_c])) {
            return $this->scoped_values[$key][$reader_c];
        }

        return $this->values[$key] ?? $default;
    }

    public function quality(?int $default = null, ?string $reader_c = null): ?int
    {
        return $this->get(self::QUALITY, $default, $reader_c);
    }

    /** @deprecated since 0.7.0 — quality() says the same: a level is not a count (`_n` is kept for counts). Removed in 0.8.0. */
    public function quality_n(?int $default = null, ?string $reader_c = null): ?int
    {
        return $this->quality($default, $reader_c);
    }

    public function lossless(bool $default = false, ?string $reader_c = null): bool
    {
        return $this->get(self::LOSSLESS, $default, $reader_c);
    }

    /** The page, 1-based — an index, hence `_i`. */
    public function page_i(int $default = 1, ?string $reader_c = null): int
    {
        return $this->get(self::PAGE, $default, $reader_c);
    }

    /** @deprecated since 0.7.0 — page_i() says the same: a page number is an index, not a count. Removed in 0.8.0. */
    public function page_n(int $default = 1, ?string $reader_c = null): int
    {
        return $this->page_i($default, $reader_c);
    }

    public function width_n(?string $reader_c = null): ?int
    {
        return $this->get(self::WIDTH, null, $reader_c);
    }

    public function height_n(?string $reader_c = null): ?int
    {
        return $this->get(self::HEIGHT, null, $reader_c);
    }

    public function colorspace_code(?string $default = null, ?string $reader_c = null): ?string
    {
        return $this->get(self::COLORSPACE, $default, $reader_c);
    }

    public function icc_profile(?string $reader_c = null): ?string
    {
        return $this->get(self::ICC_PROFILE, null, $reader_c);
    }

    public function ratio_code(?string $reader_c = null): ?string
    {
        return $this->get(self::RATIO, null, $reader_c);
    }

    public function align_x_perc(int $default = 50, ?string $reader_c = null): int
    {
        return $this->get(self::ALIGN_X, $default, $reader_c);
    }

    public function align_y_perc(int $default = 50, ?string $reader_c = null): int
    {
        return $this->get(self::ALIGN_Y, $default, $reader_c);
    }

    /** @deprecated since 0.7.0 — align_x_perc() says the same: a percentage, not a count. Removed in 0.8.0. */
    public function align_x_n(int $default = 50, ?string $reader_c = null): int
    {
        return $this->align_x_perc($default, $reader_c);
    }

    /** @deprecated since 0.7.0 — align_y_perc() says the same: a percentage, not a count. Removed in 0.8.0. */
    public function align_y_n(int $default = 50, ?string $reader_c = null): int
    {
        return $this->align_y_perc($default, $reader_c);
    }

    public function crop_left(?string $reader_c = null): int|string|null
    {
        return $this->get(self::CROP_LEFT, null, $reader_c);
    }

    public function crop_right(?string $reader_c = null): int|string|null
    {
        return $this->get(self::CROP_RIGHT, null, $reader_c);
    }

    public function crop_top(?string $reader_c = null): int|string|null
    {
        return $this->get(self::CROP_TOP, null, $reader_c);
    }

    public function crop_bottom(?string $reader_c = null): int|string|null
    {
        return $this->get(self::CROP_BOTTOM, null, $reader_c);
    }

    public function grid_columns(?string $reader_c = null): int|string|null
    {
        return $this->get(self::GRID_COLUMNS, null, $reader_c);
    }

    public function grid_rows(?string $reader_c = null): int|string|null
    {
        return $this->get(self::GRID_ROWS, null, $reader_c);
    }

    public function grid_gutter_x(?string $reader_c = null): int|string|null
    {
        return $this->get(self::GRID_GUTTER_X, null, $reader_c);
    }

    public function grid_gutter_y(?string $reader_c = null): int|string|null
    {
        return $this->get(self::GRID_GUTTER_Y, null, $reader_c);
    }

    public function grid_margin_left(?string $reader_c = null): int|string|null
    {
        return $this->get(self::GRID_MARGIN_LEFT, null, $reader_c);
    }

    public function grid_margin_right(?string $reader_c = null): int|string|null
    {
        return $this->get(self::GRID_MARGIN_RIGHT, null, $reader_c);
    }

    public function grid_margin_top(?string $reader_c = null): int|string|null
    {
        return $this->get(self::GRID_MARGIN_TOP, null, $reader_c);
    }

    public function grid_margin_bottom(?string $reader_c = null): int|string|null
    {
        return $this->get(self::GRID_MARGIN_BOTTOM, null, $reader_c);
    }

    public function grid_order_code(string $default = 'lr-tb', ?string $reader_c = null): string
    {
        return $this->get(self::GRID_ORDER, $default, $reader_c);
    }

    public function grid_expect_n(?string $reader_c = null): ?int
    {
        return $this->get(self::GRID_EXPECT, null, $reader_c);
    }

    public function grid_skip_empty(bool $default = false, ?string $reader_c = null): bool
    {
        return $this->get(self::GRID_SKIP_EMPTY, $default, $reader_c);
    }

    public function background_code(?string $reader_c = null): ?string
    {
        return $this->get(self::BACKGROUND, null, $reader_c);
    }

    public function gap_min(?string $reader_c = null): int|string|null
    {
        return $this->get(self::GAP_MIN, null, $reader_c);
    }

    public function gap_tolerance_perc(int $default = 10, ?string $reader_c = null): int
    {
        return $this->get(self::GAP_TOLERANCE, $default, $reader_c);
    }

    /** @return array<string, mixed> the unscoped bag only — scoped values are, by design, not visible without naming a reader */
    public function all(): array
    {
        return $this->values;
    }
}
