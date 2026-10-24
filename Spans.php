<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - Spans
 *
 * @package Italix\Converters
 */

declare(strict_types=1);

namespace Italix\Converters;

/**
 * Where the content is, along one axis. A margin around a scan, a gutter
 * between the pieces of a sheet, the silence around and between the takes
 * of a recording, the black frames between a video's scenes: all one
 * problem — given a profile, how much content each step along the axis
 * holds, find the runs of nothing.
 *
 * A driver turns its medium into the profile (an image: per row or column,
 * the share of pixels that differ from the background; audio: the energy of
 * each window; video: how dark each frame is) and this class does the rest.
 * Never instantiated, no pixels, no samples: a list of numbers in, index
 * ranges out. A step counts as empty when its value is at most <threshold>.
 */
final class Spans
{
    private function __construct()
    {
    }

    /**
     * The first and last step with content — what an adaptive Trimmer keeps.
     * Null when there is none at all.
     *
     * @param  list<float> $profile
     * @return array{0: int, 1: int}|null
     */
    public static function content(array $profile, float $threshold = 0.0): ?array
    {
        $first = null;
        $last  = null;
        foreach ($profile as $i => $value) {
            if ($value > $threshold) {
                $first ??= $i;
                $last    = $i;
            }
        }

        return $first === null ? null : [$first, $last];
    }

    /**
     * The runs of content, split wherever at least <min_gap_n> empty steps
     * lie between them — [first, last] each, inclusive, in order. A shorter
     * run of empty steps stays inside its span (a thin white line in a
     * picture is not a gutter); the empty steps before the first span and
     * after the last are the margins, and belong to none.
     *
     * @param  list<float> $profile
     * @return list<array{0: int, 1: int}>
     * @throws ConversionException a gap shorter than one step
     */
    public static function split(array $profile, int $min_gap_n, float $threshold = 0.0): array
    {
        if ($min_gap_n < 1) {
            throw new ConversionException("A gap is at least one step long: {$min_gap_n}.");
        }

        $spans   = [];
        $start   = null;
        $last    = null;
        $empty_n = 0;
        foreach ($profile as $i => $value) {
            if ($value <= $threshold) {
                $empty_n++;
                continue;
            }
            if ($start !== null && $empty_n >= $min_gap_n) {
                $spans[] = [$start, $last];
                $start   = null;
            }
            $start ??= $i;
            $last    = $i;
            $empty_n = 0;
        }
        if ($start !== null) {
            $spans[] = [$start, $last];
        }

        return $spans;
    }
}
