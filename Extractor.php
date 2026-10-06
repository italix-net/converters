<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - Extractor
 *
 * @package Italix\Converters
 */

declare(strict_types=1);

namespace Italix\Converters;

/**
 * Pulls one or more pieces out of a source — same format in, same format
 * out, but a different cardinality than Converter: one source becomes N
 * pieces, not one source becoming one differently-formatted result. There
 * is deliberately no (from, to) pair the way Converter has one — the format
 * never changes, only how much of the source comes back and where the
 * boundaries are drawn.
 *
 * Covers both ends of one spectrum: "give me your default partition"
 * (every page of a PDF, a uniform image grid, a driver's own built-in
 * content-detection pass such as video scene-cut analysis) and "give me
 * exactly these regions" (an explicit, arbitrary, not-necessarily-exhaustive
 * list — three specific bounding boxes an LLM decided mattered, leaving the
 * rest of the source untouched). A single explicit boundary is a legitimate
 * call too — "extract exactly this one piece" needs no separate interface.
 */
interface Extractor
{
    /** @return string[] Extensions this driver knows how to extract from, lowercase, no dot. */
    public function extensions(): array;

    /** Is whatever this driver depends on (a binary, an extension) usable right now? */
    public function is_available(): bool;

    /**
     * @param array<int, mixed>|null $boundaries Explicit locators, in this
     *   driver's own shape (a page range, a pixel bounding box, a time
     *   range — see the driver's own docblock), or null to use this
     *   driver's own default partitioning.
     * @return ConvertedDocument[] one per extracted piece, in order
     * @throws ConversionException is_available() was true and the attempt still failed
     */
    public function extract(string $source, string $extension_c, ?array $boundaries, ConversionOptions $options): array;

    /** One line, for a CLI probe. */
    public function describe(): string;
}
