<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - Merger
 *
 * @package Italix\Converters
 */

declare(strict_types=1);

namespace Italix\Converters;

/**
 * The opposite cardinality of Converter: N sources become one result.
 * pairs() mirrors Converter::pairs() exactly, just inverted — every
 * (source extension, target extension) this driver merges N-of-the-first
 * into one-of-the-second. The target extension may differ from the source
 * (several images merged into one PDF), the same way Converter allows a
 * format change; merging N sources of the same format into one of that
 * same format (several single-page PDFs into one PDF) is just the case
 * where pairs() happens to declare from === to.
 *
 * There is no MergerSet routing the way ConverterSet chains Converter hops
 * through intermediate formats: merging doesn't compose that way — a
 * direct pair lookup among registered drivers is all this needs.
 */
interface Merger
{
    /** @return array<array{0: string, 1: string}> every (source extension, target extension) this driver merges directly */
    public function pairs(): array;

    /** Is whatever this driver depends on (a binary, an extension) usable right now? */
    public function is_available(): bool;

    /**
     * @param string[] $sources raw bytes of each part, in the order they should be merged
     * @throws ConversionException is_available() was true and the attempt still failed
     */
    public function merge(array $sources, string $from_extension_c, string $to_extension_c, ConversionOptions $options): ConvertedDocument;

    /** One line, for a CLI probe. */
    public function describe(): string;
}
