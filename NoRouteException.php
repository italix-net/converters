<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - NoRouteException
 *
 * @package Italix\Converters
 */

declare(strict_types=1);

namespace Italix\Converters;

/**
 * No chain of registered, available drivers connects two formats.
 *
 * Carries what the search actually found, not just the fact that it
 * failed — the two ends of the dead end are what someone fixing a missing
 * driver needs first, not a bare "unsupported".
 */
final class NoRouteException extends ConversionException
{
    /**
     * @param string[] $reachable_extensions_c what BFS found before giving
     *   up: every format reachable from $from_extension_c with what is
     *   installed right now
     */
    public function __construct(
        private readonly string $from_extension_c,
        private readonly string $to_extension_c,
        private readonly array $reachable_extensions_c,
    ) {
        parent::__construct(sprintf(
            'No route from .%s to .%s. Reachable from .%s: %s.',
            $from_extension_c,
            $to_extension_c,
            $from_extension_c,
            $reachable_extensions_c === []
                ? '(nothing)'
                : implode(', ', array_map(static fn (string $e): string => ".{$e}", $reachable_extensions_c))
        ));
    }

    public function from_extension_code(): string
    {
        return $this->from_extension_c;
    }

    public function to_extension_code(): string
    {
        return $this->to_extension_c;
    }

    /** @return string[] */
    public function reachable_extensions(): array
    {
        return $this->reachable_extensions_c;
    }
}
