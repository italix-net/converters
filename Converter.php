<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - Converter
 *
 * @package Italix\Converters
 */

declare(strict_types=1);

namespace Italix\Converters;

/**
 * Turns a document from one format into another.
 *
 * Pairwise, unlike Italix\Documents' Renderer, which always targets HTML: a
 * Converter targets whatever the driver actually supports, and most drivers
 * cover a handful of pairs, not every source crossed with every target.
 *
 * pairs() is the single source of truth ConverterSet routes against —
 * there is deliberately no separate handles($from, $to) to fall out of sync
 * with it; a caller that only wants a yes/no answer can check pairs() itself.
 *
 * A driver that shells out (Pandoc, LibreOffice headless, ffmpeg) checks its
 * own prerequisite in is_available() rather than throwing from the
 * constructor: absence is normal, not exceptional — the same reasoning
 * Italix\Documents\RendererSet::defaults() applies to league/commonmark —
 * and it is ConverterSet's job to decide what to do about an unavailable
 * driver, not the driver's.
 *
 * convert() always receives a ConversionOptions, never null — a driver that
 * takes no options of its own simply never reads it. See ConversionOptions'
 * own docblock for why an unrecognized key is silently ignored while a
 * recognized-but-impossible one (a colorspace this driver can never
 * produce) throws UnsupportedOptionException instead.
 */
interface Converter
{
    /**
     * Every (from, to) extension pair this driver can convert directly,
     * lowercase, no dot.
     *
     * @return array<array{0: string, 1: string}>
     */
    public function pairs(): array;

    /** Is whatever this driver depends on (a binary, an extension) usable right now? */
    public function is_available(): bool;

    /**
     * @param string $from_extension_c lowercase, no dot
     * @param string $to_extension_c   lowercase, no dot
     * @throws ConversionException is_available() was true and the attempt still failed
     * @throws UnsupportedOptionException $options named something this driver recognizes but cannot do
     */
    public function convert(
        string $source,
        string $from_extension_c,
        string $to_extension_c,
        ConversionOptions $options
    ): ConvertedDocument;

    /** One line, for a CLI probe. */
    public function describe(): string;
}
