<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - UnsupportedOptionException
 *
 * @package Italix\Converters
 */

declare(strict_types=1);

namespace Italix\Converters;

/**
 * A driver was asked for an option it cannot honor — a requested colorspace
 * it cannot produce, a page number past the end of a document, and so on.
 *
 * Thrown, not ignored. A ConversionOptions key a driver has never heard of
 * is silently unread (see ConversionOptions' own docblock) — that is
 * correct, because an option meant for a different hop on the same route
 * doing nothing here is expected. This exception is for the opposite case:
 * a driver recognizes the key, understood what was asked, and specifically
 * cannot do it. Producing RGB output when CMYK was explicitly requested is
 * a correctness bug wearing a passing test; throwing here is what keeps it
 * from shipping quietly.
 */
final class UnsupportedOptionException extends ConversionException
{
    public function __construct(
        private readonly string $option_key_c,
        private readonly mixed $option_value,
        private readonly string $driver_description_c
    ) {
        parent::__construct(sprintf(
            '%s does not support %s=%s.',
            $driver_description_c,
            $option_key_c,
            is_scalar($option_value) ? (string) $option_value : gettype($option_value)
        ));
    }

    public function option_key_code(): string
    {
        return $this->option_key_c;
    }

    public function option_value(): mixed
    {
        return $this->option_value;
    }

    /** The failing driver's own describe() — so the message names the actual driver, not a class name. */
    public function driver_description(): string
    {
        return $this->driver_description_c;
    }
}
