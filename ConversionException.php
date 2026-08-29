<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - ConversionException
 *
 * @package Italix\Converters
 */

declare(strict_types=1);

namespace Italix\Converters;

class ConversionException extends \RuntimeException
{
    public function __construct(string $message, private readonly ?string $driver_stderr_c = null)
    {
        parent::__construct($message);
    }

    /**
     * Whatever a shelled-out driver wrote to stderr, when it has something a
     * plain-PHP driver never could — a missing font, a corrupt input, a
     * nonzero exit code. Null for a failure that never touched a subprocess.
     */
    public function driver_stderr(): ?string
    {
        return $this->driver_stderr_c;
    }
}
