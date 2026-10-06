<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - Trimmer
 *
 * @package Italix\Converters
 */

declare(strict_types=1);

namespace Italix\Converters;

/**
 * Shaves a prefix and/or suffix off a source — always one source in, one
 * (shorter) source out, same format. Distinct from Extractor: Extractor
 * carves regions out (possibly many, possibly arbitrary ones anywhere in
 * the source); Trimmer only ever touches the outer edges and always
 * returns exactly one result.
 *
 * $amount is null for "decide adaptively" — detect real content bounds,
 * silence, or blank frames, whichever this driver's content-analysis
 * default is. An explicit $amount is this driver's own shape: a symmetric
 * scalar, or a structured value for an asymmetric trim (left/right/top/
 * bottom pixels for an image, start/end seconds for audio or video — see
 * the driver's own docblock). Cropping to a target aspect ratio is not a
 * third mode: it is just another way of computing an explicit $amount
 * before calling trim(), the same interface either way.
 */
interface Trimmer
{
    /** @return string[] Extensions this driver knows how to trim, lowercase, no dot. */
    public function extensions(): array;

    /** Is whatever this driver depends on (a binary, an extension) usable right now? */
    public function is_available(): bool;

    /**
     * @param mixed $amount This driver's own trim spec, or null to decide adaptively.
     * @throws ConversionException is_available() was true and the attempt still failed
     */
    public function trim(string $source, string $extension_c, mixed $amount, ConversionOptions $options): ConvertedDocument;

    /** One line, for a CLI probe. */
    public function describe(): string;
}
