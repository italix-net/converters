<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - AxisLayout
 *
 * @package Italix\Converters
 */

declare(strict_types=1);

namespace Italix\Converters;

/**
 * Tracks along one axis — the columns across a sheet's width, the rows down
 * its height, and one day the segments along a recording's time. Never
 * instantiated, like CropGeometry: numbers in, numbers out, no pixels.
 *
 * One equation per axis: `margin_start + track + gutter + track + ... +
 * margin_end = size`. resolve() solves it for the tracks, given the size
 * (taking a sheet apart); fit() solves it for the size, given what has to
 * go in each track (putting one together). The pieces resolve() cut, fed
 * back to fit() with the same spec, gutter and margins, give the same
 * tracks — that is what lets a merge undo an extract (fit() says where the
 * other direction stops holding).
 *
 * **A track spec** is one of:
 *
 * - null — one track, the whole axis;
 * - an int n, or a string of digits alone — n equal tracks: the same as n
 *   times '1fr';
 * - a list like '200px 1fr 2fr' — '200px' is 200 pixels, 'Nfr' a share of
 *   what the pixel tracks and the gutters leave. With no 'fr' at all the
 *   pixel tracks must add up exactly, or it throws: all given must agree,
 *   as in CropGeometry. The unit is required: a bare '100' is a count, so
 *   one 100-pixel track is '100px', never a guess between the two.
 *
 * No percentages in a track list, on purpose: '1fr 3fr' already says
 * '25% 75%', and adds up by construction.
 *
 * **Leftover pixels** (1024 split three ways) go one each to the first
 * shared tracks, in order: 342, 341, 341. The same rule in both directions,
 * so a round trip lands on the same pixels.
 */
final class AxisLayout
{
    /** More tracks than this is a mistake, not a layout — refused before anything is allocated for them. */
    public const MAX_TRACKS_N = 10000;

    private function __construct()
    {
    }

    /** How many tracks a spec has. */
    public static function count(int|string|null $spec): int
    {
        return count(self::parse($spec));
    }

    /**
     * An int stays pixels, and so does a string like '10px'; a string like
     * '10%' is that share of <of_n>, rounded. Null is 0 — an absent margin
     * or gutter is none.
     *
     * @throws ConversionException on anything else, or a negative length
     */
    public static function pixels(int|string|null $amount, int $of_n): int
    {
        if ($amount === null) {
            return 0;
        }
        if (is_int($amount)) {
            if ($amount < 0) {
                throw new ConversionException("A length cannot be negative: {$amount}.");
            }

            return $amount;
        }
        if (preg_match('/^(\d+)px\z/', $amount, $m) === 1) {
            return (int) $m[1];
        }
        if (preg_match('/^(\d+(?:\.\d+)?)%\z/', $amount, $m) === 1) {
            return (int) round($of_n * (float) $m[1] / 100);
        }

        throw new ConversionException("Not a valid length: \"{$amount}\" — expected pixels (an integer, or \"10px\") or a percentage like \"10%\".");
    }

    /**
     * The tracks of an axis <size_n> long: [offset, size] each, in order.
     *
     * @return list<array{0: int, 1: int}>
     * @throws ConversionException the spec is malformed, the pixel tracks do not add up, or a track would be empty
     */
    public static function resolve(int $size_n, int|string|null $spec, int $gutter_n, int $start_n, int $end_n): array
    {
        self::assert_lengths([$gutter_n, $start_n, $end_n]);
        $tracks      = self::parse($spec);
        $available_n = $size_n - $start_n - $end_n - $gutter_n * (count($tracks) - 1);

        if ($available_n < count($tracks)) {
            throw new ConversionException(sprintf(
                'No room for %d track(s): %dpx, less margins %d+%d and gutters, leaves %dpx.',
                count($tracks),
                $size_n,
                $start_n,
                $end_n,
                $available_n
            ));
        }

        $fixed_n  = 0;
        $shares_n = 0.0;
        foreach ($tracks as [$kind_c, $value]) {
            $kind_c === 'px' ? $fixed_n += (int) $value : $shares_n += $value;
        }

        if ($shares_n === 0.0 && $fixed_n !== $available_n) {
            throw new ConversionException("The pixel tracks add up to {$fixed_n}px, not the {$available_n}px there is room for — add an 'fr' track to take the rest.");
        }
        if ($fixed_n > $available_n) {
            throw new ConversionException("The pixel tracks need {$fixed_n}px, and there are only {$available_n}px.");
        }

        $sizes = self::share_out($tracks, $available_n - $fixed_n, $shares_n);

        return self::offsets($sizes, $gutter_n, $start_n);
    }

    /**
     * The other direction: how long the axis must be for each track to hold
     * <content_n>[i] (the largest piece going into it), and where the tracks
     * fall. A pixel track keeps its size — content larger than it is an
     * error, never a resize; a shared track ('fr', or a counted one) is as
     * large as its content, whatever its weight: a merge fits what it is
     * given, it does not stretch a piece's cell to honour '1fr 2fr'. So
     * resolve() then fit() gives the same tracks back (the pieces have the
     * sizes resolve() cut), while fit() then resolve() does only when the
     * shared tracks' sizes already stand in their weights' ratio. An empty
     * shared track (no piece in it — the end of a declared grid that is not
     * full) is as large as the largest shared one, so the empty cells are
     * background of the grid's own size, not a collapsed line.
     *
     * @param  list<int> $content_n one per track
     * @return array{size: int, tracks: list<array{0: int, 1: int}>}
     * @throws ConversionException the counts differ, or a piece does not fit its pixel track
     */
    public static function fit(array $content_n, int|string|null $spec, int $gutter_n, int $start_n, int $end_n): array
    {
        self::assert_lengths([$gutter_n, $start_n, $end_n, ...$content_n]);
        $tracks = self::parse($spec);

        if (count($content_n) !== count($tracks)) {
            throw new ConversionException(sprintf('%d track(s) in the spec, content for %d.', count($tracks), count($content_n)));
        }

        $shared_n = 0;
        foreach ($tracks as $i => [$kind_c]) {
            if ($kind_c === 'fr') {
                $shared_n = max($shared_n, $content_n[$i]);
            }
        }

        $sizes = [];
        foreach ($tracks as $i => [$kind_c, $value]) {
            if ($kind_c === 'px' && $content_n[$i] > $value) {
                throw new ConversionException("A {$content_n[$i]}px piece does not fit track " . ($i + 1) . " ({$value}px) — resize it first; a merge never scales.");
            }
            $sizes[] = match (true) {
                $kind_c === 'px'     => (int) $value,
                $content_n[$i] === 0 => $shared_n,
                default              => $content_n[$i],
            };
        }

        $size_n = $start_n + array_sum($sizes) + $gutter_n * (count($sizes) - 1) + $end_n;

        if (!is_int($size_n)) {
            throw new ConversionException('This axis would be longer than an integer can say — a gutter or margin that large is a mistake.');
        }

        return ['size' => $size_n, 'tracks' => self::offsets($sizes, $gutter_n, $start_n)];
    }

    /**
     * @return list<array{0: string, 1: int|float}> ['px', pixels] or ['fr', share], one per track
     * @throws ConversionException
     */
    private static function parse(int|string|null $spec): array
    {
        if ($spec === null) {
            return [['fr', 1.0]];
        }
        if (is_int($spec)) {
            if ($spec < 1 || $spec > self::MAX_TRACKS_N) {
                throw new ConversionException('A track count must be between 1 and ' . self::MAX_TRACKS_N . ": {$spec}.");
            }

            return array_fill(0, $spec, ['fr', 1.0]);
        }
        if (preg_match('/^\d+\z/', trim($spec)) === 1) {
            return self::parse((int) trim($spec));   // an overlong one saturates to PHP_INT_MAX, and is refused as too many
        }

        $tracks = [];
        foreach (preg_split('/\s+/', trim($spec), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match('/^(\d+)px\z/', $token, $m) === 1 && (int) $m[1] > 0) {
                $tracks[] = ['px', (int) $m[1]];
            } elseif (preg_match('/^(\d+(?:\.\d+)?)fr\z/', $token, $m) === 1 && (float) $m[1] > 0) {
                $tracks[] = ['fr', (float) $m[1]];
            } else {
                throw new ConversionException("Not a valid track: \"{$token}\" in \"{$spec}\" — expected pixels like \"200px\" or a share like \"1fr\".");
            }
        }

        if ($tracks === []) {
            throw new ConversionException("An empty track list: \"{$spec}\".");
        }
        if (count($tracks) > self::MAX_TRACKS_N) {
            throw new ConversionException('More than ' . self::MAX_TRACKS_N . ' tracks.');
        }

        return $tracks;
    }

    /**
     * @param  list<int> $lengths
     * @throws ConversionException on a negative one
     */
    private static function assert_lengths(array $lengths): void
    {
        foreach ($lengths as $length_n) {
            if ($length_n < 0) {
                throw new ConversionException("A length cannot be negative: {$length_n}.");
            }
        }
    }

    /**
     * Pixel tracks keep their size; <free_n> is shared among the 'fr' ones by
     * weight, rounded down, and what rounding leaves goes one pixel each to
     * the first shared tracks.
     *
     * @param  list<array{0: string, 1: int|float}> $tracks
     * @return list<int>
     */
    private static function share_out(array $tracks, int $free_n, float $shares_n): array
    {
        $sizes = [];
        $given = 0;
        foreach ($tracks as [$kind_c, $value]) {
            $size_n  = $kind_c === 'px' ? (int) $value : (int) floor($free_n * $value / $shares_n);
            $sizes[] = $size_n;
            if ($kind_c === 'fr') {
                $given += $size_n;
            }
        }

        $left_n = $shares_n > 0 ? $free_n - $given : 0;
        foreach ($tracks as $i => [$kind_c]) {
            if ($left_n === 0) {
                break;
            }
            if ($kind_c === 'fr') {
                $sizes[$i]++;
                $left_n--;
            }
        }

        foreach ($sizes as $i => $size_n) {
            if ($size_n < 1) {
                throw new ConversionException('Track ' . ($i + 1) . ' would be empty — too many shares for the room there is.');
            }
        }

        return $sizes;
    }

    /**
     * @param  list<int> $sizes
     * @return list<array{0: int, 1: int}>
     */
    private static function offsets(array $sizes, int $gutter_n, int $start_n): array
    {
        $tracks   = [];
        $offset_n = $start_n;
        foreach ($sizes as $size_n) {
            $tracks[]  = [$offset_n, $size_n];
            $offset_n += $size_n + $gutter_n;
        }

        return $tracks;
    }
}
