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

    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /** The common case: no per-call knobs at all. */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Escape hatch in the other direction — build from a raw array when
     * that is what a caller already has (config parsed from JSON, for
     * instance), rather than chaining with() once per key.
     *
     * @param array<string, mixed> $values
     */
    public static function from(array $values): self
    {
        return new self($values);
    }

    /** A new instance with one key set, generic — for a key this class was never told about. */
    public function with(string $key, mixed $value): self
    {
        return new self([...$this->values, $key => $value]);
    }

    public function with_quality(int $quality_n): self
    {
        return $this->with(self::QUALITY, $quality_n);
    }

    public function with_lossless(bool $flag): self
    {
        return $this->with(self::LOSSLESS, $flag);
    }

    public function with_page(int $page_n): self
    {
        return $this->with(self::PAGE, $page_n);
    }

    public function with_width(int $width_n): self
    {
        return $this->with(self::WIDTH, $width_n);
    }

    public function with_height(int $height_n): self
    {
        return $this->with(self::HEIGHT, $height_n);
    }

    public function with_colorspace(string $colorspace_c): self
    {
        return $this->with(self::COLORSPACE, $colorspace_c);
    }

    public function with_icc_profile(string $bytes): self
    {
        return $this->with(self::ICC_PROFILE, $bytes);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** Generic read — for a key this class was never told about. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function quality_n(?int $default = null): ?int
    {
        return $this->values[self::QUALITY] ?? $default;
    }

    public function lossless(bool $default = false): bool
    {
        return $this->values[self::LOSSLESS] ?? $default;
    }

    public function page_n(int $default = 1): int
    {
        return $this->values[self::PAGE] ?? $default;
    }

    public function width_n(): ?int
    {
        return $this->values[self::WIDTH] ?? null;
    }

    public function height_n(): ?int
    {
        return $this->values[self::HEIGHT] ?? null;
    }

    public function colorspace_code(?string $default = null): ?string
    {
        return $this->values[self::COLORSPACE] ?? $default;
    }

    public function icc_profile(): ?string
    {
        return $this->values[self::ICC_PROFILE] ?? null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
