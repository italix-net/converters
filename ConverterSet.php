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
 *
 * **Multiple drivers per edge.** The graph keeps every available driver for
 * a given (from, to) pair, not just the first registered — needed so a
 * `via` entry can *pick* a specific one when more than one competes for the
 * same hop. Unconstrained resolution still defaults to the first
 * registered, exactly as before: this is fully backward compatible, and
 * for anyone never passing `via`, behavior is unchanged.
 *
 * **`via`**: an ordered list whose entries are either a format extension
 * (a *node* the route must pass through, forced even if a shorter route
 * would skip it — the same idea as a mandatory waypoint on a map) or a
 * driver's class name (an *edge* preference — wins the tie-break for
 * whichever hop it can serve, wherever that hop ends up in the route, but
 * cannot insert a hop the shortest path wouldn't otherwise include). An
 * entry is resolved as a format if it names one of the extensions actually
 * present in this graph; otherwise it is treated as a driver class name.
 * Both kinds can be mixed and given together to combine "this hop must
 * exist" with "and this driver must serve it".
 */
final class ConverterSet
{
    /** @var array<string, array<string, Converter[]>> from_extension_c => [to_extension_c => [drivers]] */
    private array $graph;

    /** @var string[] every extension appearing as either side of an edge */
    private array $formats;

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

        $this->graph   = $this->build_graph($converters);
        $this->formats = $this->collect_formats($this->graph);
    }

    /**
     * @param Converter[] $converters
     * @return array<string, array<string, Converter[]>>
     */
    private function build_graph(array $converters): array
    {
        $graph = [];

        foreach ($converters as $converter) {
            if (!$converter->is_available()) {
                continue;
            }

            foreach ($converter->pairs() as [$from_c, $to_c]) {
                // Every available driver for this pair is kept, in
                // registration order — resolve_driver() decides which one
                // actually serves a given route, defaulting to the first.
                $graph[$from_c][$to_c][] = $converter;
            }
        }

        return $graph;
    }

    /**
     * @param array<string, array<string, Converter[]>> $graph
     * @return string[]
     */
    private function collect_formats(array $graph): array
    {
        $formats = array_keys($graph);

        foreach ($graph as $to_map) {
            $formats = [...$formats, ...array_keys($to_map)];
        }

        return array_values(array_unique($formats));
    }

    /**
     * @param string[] $via
     * @return array{0: string[], 1: string[]} waypoint formats, then driver class names, each in the order given
     */
    private function partition_via(array $via): array
    {
        $waypoints = [];
        $prefs     = [];

        foreach ($via as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new ConversionException('Each `via` entry must be a non-empty format extension or driver class name.');
            }

            if (in_array($entry, $this->formats, true)) {
                $waypoints[] = $entry;
            } else {
                $prefs[] = $entry;
            }
        }

        return [$waypoints, $prefs];
    }

    /**
     * Picks which registered driver serves one edge: the first driver
     * (in $driver_prefs order) whose class matches one of the candidates
     * for this pair, or the first-registered candidate when no preference
     * matches — identical to this class's behavior before `via` existed.
     *
     * @param string[] $driver_prefs
     */
    private function resolve_driver(string $from_extension_c, string $to_extension_c, array $driver_prefs): Converter
    {
        $candidates = $this->graph[$from_extension_c][$to_extension_c] ?? [];

        foreach ($driver_prefs as $pref) {
            foreach ($candidates as $driver) {
                if (get_class($driver) === $pref || str_ends_with(get_class($driver), '\\' . $pref)) {
                    return $driver;
                }
            }
        }

        return $candidates[0];
    }

    /**
     * The shortest node sequence from $from to $to, inclusive of both ends.
     * Every hop is a lossy re-encode, so fewest hops is also least-damaging,
     * not just fastest — a property breadth-first search gets for free.
     *
     * @return string[]
     * @throws NoRouteException
     */
    private function route_segment(string $from_extension_c, string $to_extension_c): array
    {
        if ($from_extension_c === $to_extension_c) {
            return [$from_extension_c];
        }

        $queue   = [[$from_extension_c, [$from_extension_c]]];
        $visited = [$from_extension_c => true];

        while ($queue !== []) {
            [$at_c, $path] = array_shift($queue);

            foreach (array_keys($this->graph[$at_c] ?? []) as $next_c) {
                if ($next_c === $to_extension_c) {
                    return [...$path, $next_c];
                }

                if (isset($visited[$next_c])) {
                    continue;
                }

                $visited[$next_c] = true;
                $queue[]          = [$next_c, [...$path, $next_c]];
            }
        }

        $reachable_c = array_values(array_diff(array_keys($visited), [$from_extension_c]));

        throw new NoRouteException($from_extension_c, $to_extension_c, $reachable_c);
    }

    /**
     * @param string[] $stops
     * @return string[] the concatenated node sequence across every segment
     */
    private function route_segments(array $stops): array
    {
        $node_path = [$stops[0]];

        foreach (array_slice($stops, 0, -1) as $i => $stop) {
            $next_stop = $stops[$i + 1];

            if ($stop === $next_stop) {
                continue;
            }

            $segment   = $this->route_segment($stop, $next_stop);
            $node_path = [...$node_path, ...array_slice($segment, 1)];
        }

        return $node_path;
    }

    /**
     * @param string[] $node_path
     * @param string[] $driver_prefs
     * @return array<array{0: string, 1: string, 2: Converter}>
     */
    private function hops_for(array $node_path, array $driver_prefs): array
    {
        $hops = [];

        for ($i = 0; $i < count($node_path) - 1; $i++) {
            $from_c = $node_path[$i];
            $to_c   = $node_path[$i + 1];
            $hops[] = [$from_c, $to_c, $this->resolve_driver($from_c, $to_c, $driver_prefs)];
        }

        return $hops;
    }

    /**
     * @param string[] $via
     * @return array<array{0: string, 1: string, 2: Converter}> from, to, driver — empty when equal
     * @throws NoRouteException
     */
    public function route(string $from_extension_c, string $to_extension_c, array $via = []): array
    {
        [$waypoints, $driver_prefs] = $this->partition_via($via);
        $stops                      = [$from_extension_c, ...$waypoints, $to_extension_c];

        return $this->hops_for($this->route_segments($stops), $driver_prefs);
    }

    /**
     * Every route tied for the shortest hop count honoring `via`'s
     * waypoints, in every driver combination available at each hop — for
     * exploring what convert() *could* do before pinning one choice down
     * with `via`. Never used internally by convert(), which stays
     * deterministic. $slack_n widens the search to also include routes up
     * to that many hops longer than the shortest.
     *
     * @param string[] $via
     * @return array<array<array{0: string, 1: string, 2: Converter}>>
     */
    public function all_routes(string $from_extension_c, string $to_extension_c, array $via = [], int $slack_n = 0): array
    {
        [$waypoints, $driver_prefs] = $this->partition_via($via);
        $stops                      = [$from_extension_c, ...$waypoints, $to_extension_c];

        $segment_node_paths = [];

        foreach (array_slice($stops, 0, -1) as $i => $stop) {
            $next_stop             = $stops[$i + 1];
            $segment_node_paths[] = $stop === $next_stop
                ? [[$stop]]
                : $this->all_shortest_node_paths($stop, $next_stop, $slack_n);
        }

        $full_node_paths = [[]];

        foreach ($segment_node_paths as $paths) {
            $next = [];

            foreach ($full_node_paths as $prefix) {
                foreach ($paths as $path) {
                    $next[] = $prefix === [] ? $path : [...$prefix, ...array_slice($path, 1)];
                }
            }

            $full_node_paths = $next;
        }

        $routes = [];

        foreach ($full_node_paths as $node_path) {
            $hop_candidates = [];

            for ($i = 0; $i < count($node_path) - 1; $i++) {
                $hop_candidates[] = $this->ordered_candidates($node_path[$i], $node_path[$i + 1], $driver_prefs);
            }

            foreach ($this->cartesian($hop_candidates) as $drivers) {
                $route = [];

                for ($i = 0; $i < count($node_path) - 1; $i++) {
                    $route[] = [$node_path[$i], $node_path[$i + 1], $drivers[$i]];
                }

                $routes[] = $route;
            }
        }

        return $routes;
    }

    /**
     * @param string[] $driver_prefs
     * @return Converter[] candidates for one edge, preferred drivers first, in $driver_prefs order
     */
    private function ordered_candidates(string $from_extension_c, string $to_extension_c, array $driver_prefs): array
    {
        $candidates = $this->graph[$from_extension_c][$to_extension_c] ?? [];
        $preferred  = [];
        $rest       = $candidates;

        foreach ($driver_prefs as $pref) {
            foreach ($candidates as $driver) {
                if (get_class($driver) === $pref || str_ends_with(get_class($driver), '\\' . $pref)) {
                    $preferred[] = $driver;
                    $rest        = array_values(array_udiff($rest, [$driver], static fn ($a, $b) => $a === $b ? 0 : 1));
                }
            }
        }

        return [...$preferred, ...$rest];
    }

    /** A safety ceiling on how many complete paths a bounded search will collect — see all_shortest_node_paths(). */
    private const MAX_PATHS_N = 500;

    /**
     * Every simple node path from $from to $to whose hop count is at most
     * the shortest possible plus $slack_n.
     *
     * A plain BFS distance (as route_segment() computes) only ever tracks
     * the single shortest distance to each node — it does not extend to
     * "every path within N hops of shortest" without a fundamentally
     * different search, since a node's shortest distance says nothing
     * about a second, slightly longer way to reach it. A bounded
     * depth-first search from $from, pruning any branch that has already
     * used its whole hop budget, finds exactly this set directly and
     * correctly: at slack 0 it can only ever complete paths of the
     * shortest length (anything shorter would already be a completed path;
     * anything longer is pruned before completion), and at slack > 0 it
     * additionally finds paths up to that many hops longer.
     *
     * @return array<string[]>
     */
    private function all_shortest_node_paths(string $from_extension_c, string $to_extension_c, int $slack_n): array
    {
        $shortest_len_n = count($this->route_segment($from_extension_c, $to_extension_c)) - 1;
        $max_len_n      = $shortest_len_n + max(0, $slack_n);

        $paths = [];
        $this->dfs_bounded($from_extension_c, $to_extension_c, [$from_extension_c], $max_len_n, $paths);

        return $paths;
    }

    /**
     * @param string[] $trail
     * @param array<string[]> $paths
     */
    private function dfs_bounded(string $at_c, string $to_extension_c, array $trail, int $max_len_n, array &$paths): void
    {
        if (count($paths) >= self::MAX_PATHS_N) {
            return;
        }

        if ($at_c === $to_extension_c) {
            $paths[] = $trail;

            return;
        }

        if (count($trail) - 1 >= $max_len_n) {
            return;
        }

        foreach (array_keys($this->graph[$at_c] ?? []) as $next_c) {
            if (in_array($next_c, $trail, true)) {
                continue;
            }

            $this->dfs_bounded($next_c, $to_extension_c, [...$trail, $next_c], $max_len_n, $paths);
        }
    }

    /**
     * @param array<Converter[]> $lists
     * @return array<Converter[]>
     */
    private function cartesian(array $lists): array
    {
        $result = [[]];

        foreach ($lists as $list) {
            $next = [];

            foreach ($result as $prefix) {
                foreach ($list as $item) {
                    $next[] = [...$prefix, $item];
                }
            }

            $result = $next;
        }

        return $result;
    }

    /**
     * Walks route(), feeding each hop's output into the next hop's input.
     *
     * The same $options reaches every hop, unchanged — there is no per-hop
     * options map. A three-hop route where only the first hop understands
     * PAGE and only the last understands QUALITY works correctly with one
     * shared bag: each driver reads the keys it recognizes and leaves the
     * rest alone (see ConversionOptions' own docblock, including its
     * driver-scoped values for the rarer case of two drivers reading the
     * same key with different meanings). $options is not consulted by
     * routing itself — route() only ever decides which formats connect,
     * never whether a hop can honor a particular option; a hop that cannot
     * throws UnsupportedOptionException at convert() time, not earlier.
     *
     * @param string[] $via
     */
    public function convert(
        string $source,
        string $from_extension_c,
        string $to_extension_c,
        ?ConversionOptions $options = null,
        array $via = []
    ): ConvertedDocument {
        $options ??= ConversionOptions::none();
        $route     = $this->route($from_extension_c, $to_extension_c, $via);

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
