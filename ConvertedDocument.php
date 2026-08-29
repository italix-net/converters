<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - ConvertedDocument
 *
 * @package Italix\Converters
 */

declare(strict_types=1);

namespace Italix\Converters;

/**
 * The output of a conversion: the bytes, and what format they are in.
 *
 * Bytes rather than a file path, matching Converter::convert()'s input —
 * binary-safe, and it keeps a driver free to work purely in memory when the
 * underlying tool allows it. A driver that must shell out to something
 * file-only (most LibreOffice invocations) is free to write and read temp
 * files internally; that is the driver's business, not this contract's.
 */
final class ConvertedDocument
{
    public function __construct(
        private readonly string $bytes,
        private readonly string $extension_c,
        private readonly string $mime_c,
    ) {
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    /** Lowercase, no dot. */
    public function extension_code(): string
    {
        return $this->extension_c;
    }

    /** Empty when unknown — true today only of ConverterSet's identity conversion. */
    public function mime(): string
    {
        return $this->mime_c;
    }
}
