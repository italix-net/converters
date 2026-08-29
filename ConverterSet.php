<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - ConverterSet
 *
 * @package Italix\Converters
 */

declare(strict_types=1);

namespace Italix\Converters;

/**
 * The converters an application registers, routed by shortest hop count.
 *
 * Unlike Italix\Documents\RendererSet (one format in, first match wins, a
 * guaranteed fallback), there is no universal converter and no guaranteed
 * route — most useful pairs cross library boundaries (Markdown to HTML in
 * one driver set, HTML to PDF in another), and some pairs are simply not
 * reachable with what happens to be installed. route() and convert() both
 * throw rather than return null, so a caller never has to remember to check.
 *
 * The graph is built once, at construction, from is_available() drivers
 * only: an uninstalled Pandoc must not be "reachable" and fail midway
 * through a chain — it should be absent from routing the same way it is
 * absent from the machine.
 */
final class ConverterSet
{
    /** @var array<string, array<string, Converter>> from_extension_c => [to_extension_c => driver] */
    private array $graph;

    /**
     * @param Converter[] $converters
     */
    public function __construct(private readonly array $converters = [])
    {
        foreach ($converters as $converter) {
            if (!$converter instanceof Converter) {
                throw new ConversionException('A ConverterSet takes Converter instances.');
            }
        }

        $this->graph = $this->build_graph($converters);
    }

    /**
     * @param Converter[] $converters
     * @return array<string, array<string, Converter>>
     */
    private function build_graph(array $converters): array
    {
        $graph = [];

        foreach ($converters as $converter) {
            if (!$converter->is_available()) {
                continue;
            }

            foreach ($converter->pairs() as [$from_c, $to_c]) {
                // First registered driver for a pair wins — the same rule
                // RendererSet already applies to whole formats, applied
                // here to individual edges instead.
                $graph[$from_c][$to_c] ??= $converter;
            }
        }

        return $graph;
    }

    /**
     * The shortest chain of drivers from one format to another. Every hop
     * is a lossy re-encode, so fewest hops is also least-damaging, not
     * just fastest — a property breadth-first search gets for free.
     *
     * @return array<array{0: string, 1: string, 2: Converter}> from, to, driver — empty when equal
     * @throws NoRouteException
     */
    public function route(string $from_extension_c, string $to_extension_c): array
    {
        if ($from_extension_c === $to_extension_c) {
            return [];
        }

        $queue   = [[$from_extension_c, []]];
        $visited = [$from_extension_c => true];

        while ($queue !== []) {
            [$at_c, $path] = array_shift($queue);

            foreach ($this->graph[$at_c] ?? [] as $next_c => $converter) {
                $hop = [$at_c, $next_c, $converter];

                if ($next_c === $to_extension_c) {
                    return [...$path, $hop];
                }

                if (isset($visited[$next_c])) {
                    continue;
                }

                $visited[$next_c] = true;
                $queue[]          = [$next_c, [...$path, $hop]];
            }
        }

        // $from_extension_c itself is excluded: trivially "reaching" your own
        // starting point is not a useful answer to "what did you actually
        // find" when a real route is what was being looked for.
        $reachable_c = array_values(array_diff(array_keys($visited), [$from_extension_c]));

        throw new NoRouteException($from_extension_c, $to_extension_c, $reachable_c);
    }

    /**
     * Walks route(), feeding each hop's output into the next hop's input.
     *
     * The same $options reaches every hop, unchanged — there is no per-hop
     * options map. A three-hop route where only the first hop understands
     * PAGE and only the last understands QUALITY works correctly with one
     * shared bag: each driver reads the keys it recognizes and leaves the
     * rest alone (see ConversionOptions' own docblock). $options is not
     * consulted by routing itself — route() only ever decides which
     * formats connect, never whether a hop can honor a particular option;
     * a hop that cannot throws UnsupportedOptionException at convert() time,
     * not earlier. A real simplification, not an oversight: honoring that
     * at route-selection time would mean every driver exposing which
     * options it supports as queryable capability, not just as a
     * convert()-time throw, and nothing here needs that yet.
     */
    public function convert(
        string $source,
        string $from_extension_c,
        string $to_extension_c,
        ?ConversionOptions $options = null
    ): ConvertedDocument {
        $options ??= ConversionOptions::none();
        $route     = $this->route($from_extension_c, $to_extension_c);

        if ($route === []) {
            // Identity — nothing to ask a driver, so mime resolution here
            // is an open point (probably belongs to Italix\Storage's
            // MimeCatalog rather than a second one invented in this library).
            return new ConvertedDocument($source, $to_extension_c, '');
        }

        $bytes  = $source;
        $result = null;

        foreach ($route as [$from_c, $to_c, $converter]) {
            $result = $converter->convert($bytes, $from_c, $to_c, $options);
            $bytes  = $result->bytes();
        }

        return $result;
    }

    /** @return string[] one line per registered, available driver — for a CLI probe */
    public function describe(): array
    {
        return array_values(array_map(
            static fn (Converter $c): string => $c->describe(),
            array_filter($this->converters, static fn (Converter $c): bool => $c->is_available())
        ));
    }
}
