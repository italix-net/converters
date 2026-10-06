<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - Pipeline
 *
 * @package Italix\Converters
 */

declare(strict_types=1);

namespace Italix\Converters;

/**
 * Runs an explicit, ordered list of Converter steps — the deliberate
 * counterpart to ConverterSet. ConverterSet answers "find me the best way
 * from format A to format Z automatically"; Pipeline answers "run exactly
 * these operations, in exactly this order, because I decided that, not a
 * shortest-path search."
 *
 * This exists because ConverterSet structurally cannot express some real
 * sequences: its graph nodes are formats, and route() only ever returns a
 * simple path — the same format can never appear twice in one route. A
 * denoise -> upscale -> sharpen chain is three png -> png steps in a row;
 * no route-finding algorithm can produce that, because "shortest path"
 * stops meaning anything once a node can repeat (nothing would bound the
 * search). That is not a routing gap to close — it is a different kind of
 * request: a caller who already knows the exact steps, not one asking to
 * have them discovered.
 *
 * Each step carries its own, fully independent ConversionOptions — unlike
 * ConverterSet::convert(), where one options bag travels through every hop
 * of a discovered route. That sidesteps the need for ConversionOptions'
 * driver-scoping here: there is nothing to disambiguate when nothing is
 * shared between steps in the first place.
 *
 * Scoped to Converter steps only, deliberately not (yet) also sequencing
 * Extractor/Trimmer/Merger steps: a step that forks into N pieces (an
 * Extractor) turns a linear pipeline into a fan-out DAG, where each branch
 * may need its own different follow-up — real, but a bigger problem with no
 * concrete shape proven yet. Composing that by hand (call extract(), then
 * run a Pipeline per resulting piece) works today without this class
 * needing to grow to cover it speculatively.
 */
final class Pipeline
{
    /**
     * @param array<array{driver?: Converter, to?: string, options?: ConversionOptions}> $steps
     *   `driver` is required at runtime — declared optional here only so a
     *   caller-supplied array missing it is still checked, not assumed
     *   away by static analysis. `to` defaults to the running extension
     *   (no format change at that step) when omitted; `options` defaults
     *   to ConversionOptions::none().
     */
    public function __construct(private readonly array $steps)
    {
    }

    public function run(string $source, string $from_extension_c): ConvertedDocument
    {
        $bytes  = $source;
        $ext_c  = $from_extension_c;
        $result = null;

        foreach ($this->steps as $i => $step) {
            if (!isset($step['driver']) || !$step['driver'] instanceof Converter) {
                throw new ConversionException("Pipeline step {$i} needs a 'driver' key holding a Converter instance.");
            }

            $to_c    = $step['to'] ?? $ext_c;
            $options = $step['options'] ?? ConversionOptions::none();

            $result = $step['driver']->convert($bytes, $ext_c, $to_c, $options);
            $bytes  = $result->bytes();
            $ext_c  = $result->extension_code();
        }

        if ($result === null) {
            throw new ConversionException('A Pipeline needs at least one step.');
        }

        return $result;
    }

    /** @return string[] one line per step, in order — for a CLI probe */
    public function describe(): array
    {
        return array_map(
            static fn (array $step): string => $step['driver']->describe(),
            $this->steps
        );
    }
}
