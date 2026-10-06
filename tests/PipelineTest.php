<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - Pipeline tests
 *
 * Against a fake Converter double, deliberately — this suite proves
 * Pipeline's own job (run exactly these steps, in exactly this order, each
 * with its own independent options), which needs no external binary. The
 * three-same-format-hops-in-a-row case (denoise -> upscale -> sharpen) is
 * the actual reason this class exists rather than another ConverterSet
 * feature: route() can never produce that, since its graph nodes are
 * formats and a route never revisits one — see Pipeline's own docblock.
 *
 * Run: php src/Libs/Italix/Converters/tests/PipelineTest.php
 */

declare(strict_types=1);

require __DIR__ . '/../Converter.php';
require __DIR__ . '/../ConvertedDocument.php';
require __DIR__ . '/../ConversionException.php';
require __DIR__ . '/../ConversionOptions.php';
require __DIR__ . '/../UnsupportedOptionException.php';
require __DIR__ . '/../Pipeline.php';

use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Converters\Converter;
use Italix\Converters\ConvertedDocument;
use Italix\Converters\Pipeline;

/**
 * Appends a marker and, when QUALITY is set, that too — so a test can read
 * the output and tell exactly which steps ran, in which order, and with
 * which per-step options, which is what makes this a real assertion rather
 * than a shape check.
 */
final class FakeStep implements Converter
{
    public function __construct(private readonly string $marker_c)
    {
    }

    public function pairs(): array
    {
        // Never actually consulted by Pipeline — a step's driver is called
        // directly, not routed — but Converter requires it.
        return [];
    }

    public function is_available(): bool
    {
        return true;
    }

    public function convert(string $source, string $from_extension_c, string $to_extension_c, ConversionOptions $options): ConvertedDocument
    {
        $quality_suffix_c = $options->has(ConversionOptions::QUALITY) ? "(q{$options->quality_n()})" : '';

        return new ConvertedDocument("{$source}+{$this->marker_c}{$quality_suffix_c}", $to_extension_c, '');
    }

    public function describe(): string
    {
        return "fake-step:{$this->marker_c}";
    }
}

$failures = 0;
$checks_n = 0;

// A closure capturing $failures/$checks_n by reference, not `global` — a
// static analyser cannot see through a global written from inside a
// function, which makes the final exit()'s ternary look unreachable.
$check = function (string $label, mixed $actual, mixed $expected) use (&$failures, &$checks_n): void {
    $checks_n++;
    if ($actual !== $expected) {
        $failures++;
        printf("FAIL %s: expected %s, got %s\n", $label, var_export($expected, true), var_export($actual, true));
        return;
    }
    printf("ok   %s\n", $label);
};

// -----------------------------------------------------------------------------
// the case route() structurally cannot do: the same format, three steps in a row

$denoise  = new FakeStep('denoise');
$upscale  = new FakeStep('upscale');
$sharpen  = new FakeStep('sharpen');
$compress = new FakeStep('compress');

$pipeline = new Pipeline([
    ['driver' => $denoise],
    ['driver' => $upscale, 'options' => ConversionOptions::none()->with_quality(2)],
    ['driver' => $sharpen],
    ['driver' => $compress, 'to' => 'webp', 'options' => ConversionOptions::none()->with_quality(85)],
]);

$result = $pipeline->run('SOURCE', 'png');

$check(
    'four steps ran, in order, each with its own independent options — png->png->png->png->webp',
    $result->bytes(),
    'SOURCE+denoise+upscale(q2)+sharpen+compress(q85)'
);
$check('the final extension is the last step\'s target, not the running format from before it', $result->extension_code(), 'webp');

// -----------------------------------------------------------------------------
// a step with no 'to' carries the running format forward unchanged

$same_format_only = new Pipeline([
    ['driver' => $denoise],
]);
$check('a step with no explicit `to` keeps the format the run() call started with', $same_format_only->run('S', 'png')->extension_code(), 'png');

// -----------------------------------------------------------------------------
// each step's options are genuinely independent — no shared bag, no scoping needed

$independent = new Pipeline([
    ['driver' => $upscale, 'options' => ConversionOptions::none()->with_quality(1)],
    ['driver' => $upscale, 'options' => ConversionOptions::none()->with_quality(9)],
]);
$check(
    'the same driver appears twice with two different quality values, unambiguously — impossible through ConverterSet, trivial here',
    $independent->run('S', 'png')->bytes(),
    'S+upscale(q1)+upscale(q9)'
);

// -----------------------------------------------------------------------------
// failure modes

$threw = false;
try {
    (new Pipeline([]))->run('S', 'png');
} catch (ConversionException $e) {
    $threw = true;
    $check('the empty-pipeline message says so', str_contains($e->getMessage(), 'at least one step'), true);
}
$check('an empty Pipeline throws rather than silently returning the untouched source', $threw, true);

$malformed_threw = false;
try {
    (new Pipeline([['to' => 'png']]))->run('S', 'jpg'); // @phpstan-ignore-line deliberate misuse — missing 'driver'
} catch (ConversionException $e) {
    $malformed_threw = true;
    $check('the message names which step is missing its driver', str_contains($e->getMessage(), 'step 0'), true);
}
$check('a step missing `driver` fails loudly, not with an undefined-key warning', $malformed_threw, true);

// -----------------------------------------------------------------------------
// describe() reports the real step sequence, for a CLI probe

$check(
    'describe() lists every step\'s driver, in order',
    $pipeline->describe(),
    ['fake-step:denoise', 'fake-step:upscale', 'fake-step:sharpen', 'fake-step:compress']
);

printf("\n%d checks, %d failures\n", $checks_n, $failures);
exit($failures === 0 ? 0 : 1);
