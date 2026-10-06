<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Converters - routing tests
 *
 * All against a FakeConverter test double, deliberately — this suite proves
 * the graph search itself (shortest path, availability filtering, tie
 * breaking, byte threading across hops), which needs no external binary and
 * should never depend on one to run.
 *
 * Run: php src/Libs/Italix/Converters/tests/ConverterSetTest.php
 */

declare(strict_types=1);

require __DIR__ . '/../Converter.php';
require __DIR__ . '/../ConvertedDocument.php';
require __DIR__ . '/../ConversionException.php';
require __DIR__ . '/../ConversionOptions.php';
require __DIR__ . '/../UnsupportedOptionException.php';
require __DIR__ . '/../NoRouteException.php';
require __DIR__ . '/../ConverterSet.php';

use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Converters\Converter;
use Italix\Converters\ConvertedDocument;
use Italix\Converters\ConverterSet;
use Italix\Converters\NoRouteException;
use Italix\Converters\UnsupportedOptionException;

/**
 * Converts by appending a marker to the bytes, so a test can read the
 * output and tell exactly which drivers touched it, and in which order —
 * that is what makes the multi-hop tests a real assertion instead of a
 * shape check. Ignores $options entirely, same as a driver with nothing to
 * configure — most of this suite is about routing, not options.
 */
final class FakeConverter implements Converter
{
    /** @param array<array{0: string, 1: string}> $pairs */
    public function __construct(
        private readonly array $pairs,
        private readonly string $marker_c,
        private readonly bool $available_flag = true,
    ) {
    }

    public function pairs(): array
    {
        return $this->pairs;
    }

    public function is_available(): bool
    {
        return $this->available_flag;
    }

    public function convert(
        string $source,
        string $from_extension_c,
        string $to_extension_c,
        ConversionOptions $options
    ): ConvertedDocument {
        return new ConvertedDocument($source . "+{$this->marker_c}", $to_extension_c, '');
    }

    public function describe(): string
    {
        return "fake:{$this->marker_c}";
    }
}

/**
 * Reads QUALITY and PAGE — two keys a real driver might recognize — and
 * bakes both into the output, so a test can tell whether $options actually
 * reached this hop and survived being passed through ConverterSet::convert()
 * rather than being dropped or defaulted silently. Throws
 * UnsupportedOptionException on any COLORSPACE other than rgb/unset, the
 * same shape a real driver that cannot do CMYK is expected to use.
 */
final class OptionsAwareFakeConverter implements Converter
{
    public function pairs(): array
    {
        return [['raw', 'out']];
    }

    public function is_available(): bool
    {
        return true;
    }

    public function convert(
        string $source,
        string $from_extension_c,
        string $to_extension_c,
        ConversionOptions $options
    ): ConvertedDocument {
        $colorspace_c = $options->colorspace_code(ConversionOptions::COLORSPACE_RGB);

        if ($colorspace_c !== ConversionOptions::COLORSPACE_RGB) {
            throw new UnsupportedOptionException(ConversionOptions::COLORSPACE, $colorspace_c, $this->describe());
        }

        return new ConvertedDocument(
            $source . "+q{$options->quality_n(90)}+p{$options->page_n(1)}",
            $to_extension_c,
            ''
        );
    }

    public function describe(): string
    {
        return 'fake:options-aware (rgb only)';
    }
}

/**
 * Same shape as FakeConverter, but as two distinctly-*named* classes rather
 * than two instances of one class — `via`'s driver-preference matching
 * works by class name, and two instances of FakeConverter share a class, so
 * they cannot be told apart by a `via` entry the way two real drivers
 * (GdImageConverter vs. a hypothetical AiUpscaleConverter) always can be.
 */
final class NamedConverterAlpha implements Converter
{
    /** @param array<array{0: string, 1: string}> $pairs */
    public function __construct(private readonly array $pairs, private readonly string $marker_c)
    {
    }

    public function pairs(): array
    {
        return $this->pairs;
    }

    public function is_available(): bool
    {
        return true;
    }

    public function convert(string $source, string $from_extension_c, string $to_extension_c, ConversionOptions $options): ConvertedDocument
    {
        return new ConvertedDocument($source . "+{$this->marker_c}", $to_extension_c, '');
    }

    public function describe(): string
    {
        return "fake:{$this->marker_c}";
    }
}

final class NamedConverterBeta implements Converter
{
    /** @param array<array{0: string, 1: string}> $pairs */
    public function __construct(private readonly array $pairs, private readonly string $marker_c)
    {
    }

    public function pairs(): array
    {
        return $this->pairs;
    }

    public function is_available(): bool
    {
        return true;
    }

    public function convert(string $source, string $from_extension_c, string $to_extension_c, ConversionOptions $options): ConvertedDocument
    {
        return new ConvertedDocument($source . "+{$this->marker_c}", $to_extension_c, '');
    }

    public function describe(): string
    {
        return "fake:{$this->marker_c}";
    }
}

/** Reads QUALITY, same key OptionsAwareFakeConverter reads — but a different driver, so the two can collide on purpose. */
final class SecondOptionsAwareFakeConverter implements Converter
{
    public function pairs(): array
    {
        return [['raw', 'out']];
    }

    public function is_available(): bool
    {
        return true;
    }

    public function convert(string $source, string $from_extension_c, string $to_extension_c, ConversionOptions $options): ConvertedDocument
    {
        return new ConvertedDocument($source . "+second-q{$options->quality_n(90, static::class)}", $to_extension_c, '');
    }

    public function describe(): string
    {
        return 'fake:second-options-aware';
    }
}

$failures = 0;
$checks_n = 0;

function check(string $label, mixed $actual, mixed $expected): void
{
    global $failures, $checks_n;
    $checks_n++;
    if ($actual !== $expected) {
        $failures++;
        printf("FAIL %s: expected %s, got %s\n", $label, var_export($expected, true), var_export($actual, true));
        return;
    }
    printf("ok   %s\n", $label);
}

function check_true(string $label, bool $actual): void
{
    check($label, $actual, true);
}

// -----------------------------------------------------------------------------
// direct route, one hop

$md_to_html = new FakeConverter([['md', 'html']], 'md2html');
$set        = new ConverterSet([$md_to_html]);

$route = $set->route('md', 'html');
check('a direct pair routes in one hop', count($route), 1);
check('...through the driver that declared it', $route[0][2], $md_to_html);
check('convert() applies that one hop', $set->convert('SOURCE', 'md', 'html')->bytes(), 'SOURCE+md2html');

// -----------------------------------------------------------------------------
// multi-hop: no direct driver, but a chain exists

$html_to_pdf = new FakeConverter([['html', 'pdf']], 'html2pdf');
$chained     = new ConverterSet([$md_to_html, $html_to_pdf]);

$route = $chained->route('md', 'pdf');
check('no direct md->pdf driver still finds a two-hop route', count($route), 2);
check('...first hop is md->html', [$route[0][0], $route[0][1]], ['md', 'html']);
check('...second hop is html->pdf', [$route[1][0], $route[1][1]], ['html', 'pdf']);
check(
    'convert() threads bytes through both hops in order',
    $chained->convert('SOURCE', 'md', 'pdf')->bytes(),
    'SOURCE+md2html+html2pdf'
);
check('the final extension is the target, not the last hop\'s literal name', $chained->convert('S', 'md', 'pdf')->extension_code(), 'pdf');

// -----------------------------------------------------------------------------
// identity: asking to convert a format into itself

check('route() from a format to itself is the empty route', $chained->route('md', 'md'), []);
$identity = $chained->convert('UNCHANGED', 'md', 'md');
check('convert() to the same format returns the source untouched', $identity->bytes(), 'UNCHANGED');
check('...tagged with that extension', $identity->extension_code(), 'md');

// -----------------------------------------------------------------------------
// an unavailable driver is not part of the graph

$installed   = new FakeConverter([['md', 'html']], 'installed');
$uninstalled = new FakeConverter([['html', 'pdf']], 'uninstalled', available_flag: false);
$partial     = new ConverterSet([$installed, $uninstalled]);

check_true('an available direct pair still routes', count($partial->route('md', 'html')) === 1);

$threw = false;
try {
    $partial->route('md', 'pdf');
} catch (NoRouteException $e) {
    $threw = true;
    check('the exception names what it was looking for', [$e->from_extension_code(), $e->to_extension_code()], ['md', 'pdf']);
    check('...and what it actually found, which excludes the unavailable hop', $e->reachable_extensions(), ['html']);
    check('...reflected in the message', str_contains($e->getMessage(), 'Reachable from .md: .html.'), true);
}
check_true('an uninstalled driver makes the route unreachable, not silently skipped mid-chain', $threw);

// -----------------------------------------------------------------------------
// no route at all: the exception says so plainly, not with a stray path

$isolated = new ConverterSet([]);
$threw    = false;
try {
    $isolated->route('md', 'mp3');
} catch (NoRouteException $e) {
    $threw = true;
    check('nothing reachable is reported honestly, not as an empty implosion', $e->reachable_extensions(), []);
    check('...and says so in words', str_contains($e->getMessage(), 'Reachable from .md: (nothing).'), true);
}
check_true('a format with no drivers at all still throws NoRouteException', $threw);

// -----------------------------------------------------------------------------
// first registered driver for a pair wins, same rule as RendererSet

$first_driver  = new FakeConverter([['png', 'webp']], 'first');
$second_driver = new FakeConverter([['png', 'webp']], 'second');
$tied          = new ConverterSet([$first_driver, $second_driver]);

check(
    'when two drivers cover the same pair, the first one registered is used',
    $tied->convert('S', 'png', 'webp')->bytes(),
    'S+first'
);

// -----------------------------------------------------------------------------
// shortest path is chosen even when a longer one is also reachable
//
// Adversarial on purpose: a->c->d (2 hops, registered first) versus
// a->b->e->d (3 hops, registered second). A search that explores whatever
// it most recently pushed instead of a real breadth-first order would keep
// diving deeper into the b/e branch and return the 3-hop route without ever
// looking at c — a weaker graph (say, a direct a->d edge next to one detour)
// would not tell BFS apart from that bug, because the direct edge gets
// found on the very first expansion regardless of traversal order.

$a_to_c = new FakeConverter([['a', 'c']], 'a2c');
$c_to_d = new FakeConverter([['c', 'd']], 'c2d');
$a_to_b = new FakeConverter([['a', 'b']], 'a2b');
$b_to_e = new FakeConverter([['b', 'e']], 'b2e');
$e_to_d = new FakeConverter([['e', 'd']], 'e2d');

$shortest = new ConverterSet([$a_to_c, $c_to_d, $a_to_b, $b_to_e, $e_to_d]);

$route = $shortest->route('a', 'd');
check('the 2-hop route wins over the reachable 3-hop detour', count($route), 2);
check('...specifically through c, not through the longer b->e branch', [$route[0][1], $route[1][1]], ['c', 'd']);

// -----------------------------------------------------------------------------
// describe() reports only available drivers

$described = new ConverterSet([$installed, $uninstalled]);
check('describe() lists only what is actually usable right now', $described->describe(), ['fake:installed']);

// -----------------------------------------------------------------------------
// construction rejects anything that is not a Converter

$rejected = false;
try {
    new ConverterSet([$installed, 'not a converter']); // @phpstan-ignore-line deliberate misuse
} catch (ConversionException $e) {
    $rejected = true;
}
check_true('a ConverterSet refuses a non-Converter entry instead of failing later, obscurely', $rejected);

// -----------------------------------------------------------------------------
// ConversionOptions: reaches the driver, survives the default, is not required

$options_set = new ConverterSet([new OptionsAwareFakeConverter()]);

check(
    'convert() with no options at all still works — ConverterSet supplies ConversionOptions::none()',
    $options_set->convert('S', 'raw', 'out')->bytes(),
    'S+q90+p1' // the driver's own defaults, because none() has neither key set
);

check(
    'an explicit ConversionOptions reaches the driver, both keys read correctly',
    $options_set->convert('S', 'raw', 'out', ConversionOptions::none()->with_quality(50)->with_page(3))->bytes(),
    'S+q50+p3'
);

check(
    'a key the caller never set falls back to the driver\'s own default, not to none()\'s absence of one',
    $options_set->convert('S', 'raw', 'out', ConversionOptions::none()->with_page(7))->bytes(),
    'S+q90+p7' // quality untouched, page overridden
);

$colorspace_threw = false;
try {
    $options_set->convert('S', 'raw', 'out', ConversionOptions::none()->with_colorspace(ConversionOptions::COLORSPACE_CMYK));
} catch (UnsupportedOptionException $e) {
    $colorspace_threw = true;
    check('UnsupportedOptionException names the key that failed', $e->option_key_code(), ConversionOptions::COLORSPACE);
    check('...and the value that was rejected', $e->option_value(), ConversionOptions::COLORSPACE_CMYK);
    check('...and the driver, via its own describe()', $e->driver_description(), 'fake:options-aware (rgb only)');
    check('...with a readable message naming both', str_contains($e->getMessage(), 'colorspace=cmyk'), true);
}
check_true('a colorspace this driver cannot produce throws, rather than silently returning rgb', $colorspace_threw);

check(
    'requesting the colorspace a driver already produces is not "unsupported"',
    $options_set->convert('S', 'raw', 'out', ConversionOptions::none()->with_colorspace(ConversionOptions::COLORSPACE_RGB))->bytes(),
    'S+q90+p1'
);

// -----------------------------------------------------------------------------
// the same ConversionOptions instance reaches every hop of a multi-hop route

$hop_one = new OptionsAwareFakeConverter(); // raw -> out
$hop_two = new class implements Converter {
    public function pairs(): array
    {
        return [['out', 'final']];
    }

    public function is_available(): bool
    {
        return true;
    }

    public function convert(string $source, string $from_extension_c, string $to_extension_c, ConversionOptions $options): ConvertedDocument
    {
        // Reads the same PAGE key the first hop already read — proving this
        // is the identical $options object, not a copy scoped to one hop.
        return new ConvertedDocument($source . "+second-hop-saw-page{$options->page_n(1)}", $to_extension_c, '');
    }

    public function describe(): string
    {
        return 'fake:second-hop';
    }
};

$multi_hop_options = new ConverterSet([$hop_one, $hop_two]);

check(
    'both hops read the same PAGE value from one shared ConversionOptions',
    $multi_hop_options->convert('S', 'raw', 'final', ConversionOptions::none()->with_page(9))->bytes(),
    'S+q90+p9+second-hop-saw-page9'
);

// -----------------------------------------------------------------------------
// via: a format waypoint forces a route even when a shorter one bypasses it

$forced = $shortest->route('a', 'd', via: ['b']);
check('via forces the route through b, even though a shorter route bypasses it', count($forced), 3);
check('...b is genuinely the first hop\'s destination', $forced[0][1], 'b');
check('...the route still reaches d in the end', $forced[2][1], 'd');

check(
    'convert() honors via the same way route() does',
    $shortest->convert('S', 'a', 'd', via: ['b'])->bytes(),
    'S+a2b+b2e+e2d'
);

// -----------------------------------------------------------------------------
// via: a driver preference forces a specific driver on a hop two drivers compete for

$alpha     = new NamedConverterAlpha([['png', 'webp']], 'alpha');
$beta      = new NamedConverterBeta([['png', 'webp']], 'beta');
$competing = new ConverterSet([$alpha, $beta]);

check('unconstrained, the first-registered driver wins — unchanged default behavior', $competing->convert('S', 'png', 'webp')->bytes(), 'S+alpha');
check('via names the second driver by class, and it wins instead', $competing->convert('S', 'png', 'webp', via: [NamedConverterBeta::class])->bytes(), 'S+beta');

$preferred_route = $competing->route('png', 'webp', via: [NamedConverterBeta::class]);
check('route() itself reflects the preferred driver, not just convert()', $preferred_route[0][2], $beta);

check(
    'a via entry matching neither a known format nor any registered driver has no effect',
    $competing->convert('S', 'png', 'webp', via: ['NoSuchDriverAnywhere'])->bytes(),
    'S+alpha'
);

// -----------------------------------------------------------------------------
// via: a format waypoint and a driver preference combined

$second_e_to_d = new NamedConverterBeta([['e', 'd']], 'e2d-ALT');
$mixed      = new ConverterSet([$a_to_c, $c_to_d, $a_to_b, $b_to_e, $e_to_d, $second_e_to_d]);

$mixed_route = $mixed->route('a', 'd', via: ['b', NamedConverterBeta::class]);
check('the waypoint still forces the long route', count($mixed_route), 3);
check('...and the driver preference wins the e->d hop specifically, not the first-registered one', $mixed_route[2][2], $second_e_to_d);

// -----------------------------------------------------------------------------
// all_routes(): every route tied for shortest, in every driver combination

$all_competing = $competing->all_routes('png', 'webp');
check('two drivers tied on one hop produce two full route alternatives', count($all_competing), 2);
check('...the default (first-registered) combination is included', $all_competing[0][0][2], $alpha);
check('...and the alternate', $all_competing[1][0][2], $beta);

$ties_only = $shortest->all_routes('a', 'd');
check('no slack: only the tied-shortest route is returned', count($ties_only), 1);
check('...it is the 2-hop route through c', count($ties_only[0]), 2);

$with_slack = $shortest->all_routes('a', 'd', slack_n: 1);
check('slack=1 also includes the 3-hop detour through b and e', count($with_slack), 2);

// -----------------------------------------------------------------------------
// driver-scoped ConversionOptions: two drivers reading the same key differently, disambiguated by identity

$scoped_set = new ConverterSet([new OptionsAwareFakeConverter()]);

$unscoped_options = ConversionOptions::none()->with_quality(77);
check(
    'an unscoped QUALITY reaches the driver exactly as before — no behavior change for anyone not using scoping',
    $scoped_set->convert('S', 'raw', 'out', $unscoped_options)->bytes(),
    'S+q77+p1'
);

$scoped_for_someone_else = ConversionOptions::none()
    ->with_quality(90)
    ->with_quality(4, SecondOptionsAwareFakeConverter::class);
check(
    'a value scoped to a DIFFERENT driver is invisible to this one — it still sees the unscoped default',
    $scoped_set->convert('S', 'raw', 'out', $scoped_for_someone_else)->bytes(),
    'S+q90+p1'
);

check(
    'the scoped value reaches ONLY the driver it was scoped to, when that driver is the one asked',
    (new SecondOptionsAwareFakeConverter())->convert('S', 'raw', 'out', $scoped_for_someone_else)->bytes(),
    'S+second-q4'
);

printf("\n%d checks, %d failures\n", $checks_n, $failures);
exit($failures === 0 ? 0 : 1);
