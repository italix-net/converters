# italix/converters

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MPL%202.0-blue.svg)](LICENSE)

A pairwise format-conversion contract, and a driver registry that routes between formats no single
driver connects directly.

```php
use Italix\Converters\ConverterSet;

$set = new ConverterSet([$markdown_to_html, $html_to_pdf, $text_to_mp3, /* … */]);

$set->convert($bytes, 'md', 'pdf');  // one driver per hop, chained automatically
$set->convert($bytes, 'md', 'mp3');  // md -> html -> text -> mp3, if that's what's registered
```

> **Status: the contract, the router, and two real consumers.** `italix/documents` (Markdown → HTML →
> PDF) and `italix/media` (raster image conversion, PDF-page-to-image) both build their `ConverterSet`
> on this library and register real drivers into it — including, together, a proven cross-library
> route: `md → html → pdf → png`. 34 assertions here in `tests/ConverterSetTest.php`, still all
> against a fake in-memory driver, because the routing logic itself needs no external binary to
> verify — the real drivers are tested where they live.

## The idea

Converting one document format into another is almost always someone else's problem solved by a
binary you shell out to — Pandoc, LibreOffice headless, ffmpeg. What is *not* someone else's
problem is: which binary handles which pair, what happens when the pair you need isn't handled by
any one binary directly, and what happens when that binary isn't installed. That's what this
library is — the plumbing, not the drivers.

A `Converter` is one driver's honest description of itself: the exact `(from, to)` extension pairs
it can convert directly, and whether it's actually usable right now. A `ConverterSet` is a
collection of those, and it does the one thing no single driver can: find a route when nothing
converts `.docx` to `.mp4` directly, by chaining `.docx → .pdf → .png → .mp4` through three
different drivers that have never heard of each other.

This is deliberately its own library rather than living inside `italix/documents` or a future
`italix/media`. Both of those are real, wanted consumers — one drives document formats
(odt/docx/pdf/markdown), the other would drive image/audio/video/3D formats — and neither owns the
concept of "convert format A to format B by any means necessary." Putting the contract in either
one would make the other's dependency direction backwards.

## Installation

```bash
composer require italix/converters
```

Requires PHP 8.1+. No other dependency — a driver is free to require whatever it needs (an
extension, a binary on `$PATH`), but that requirement belongs to the driver, not to this library.

## The contract

```php
interface Converter
{
    /** @return array<array{0: string, 1: string}> every (from, to) pair this driver handles directly */
    public function pairs(): array;

    /** Is whatever this driver depends on (a binary, an extension) usable right now? */
    public function is_available(): bool;

    public function convert(
        string $source,
        string $from_extension_c,
        string $to_extension_c,
        ConversionOptions $options
    ): ConvertedDocument;

    /** One line, for a CLI probe. */
    public function describe(): string;
}
```

`pairs()` is the single source of truth `ConverterSet` routes against — there's no separate
`handles($from, $to)` that could fall out of sync with it. `is_available()` exists so a driver that
shells out can report "not installed" instead of throwing from its constructor: absence of a binary
is a normal, expected state for a driver to be in, not an exceptional one.

## Options

`convert()` always receives a `ConversionOptions` — never null, so a driver never has to null-check.
The common case is `ConversionOptions::none()`, and `ConverterSet::convert()` supplies it
automatically when a caller doesn't pass one:

```php
use Italix\Converters\ConversionOptions;

$set->convert($bytes, 'png', 'webp');                                    // no options — the common case
$set->convert($bytes, 'png', 'webp', ConversionOptions::none()->with_quality(95));
$set->convert($bytes, 'pdf', 'png', ConversionOptions::none()->with_page(2));
```

A handful of keys recur across drivers that otherwise share nothing — a JPEG encoder and a WebP
encoder both have a "quality"; a paginated source and a paginated target both have a "page" — those
get a named constant (`QUALITY`, `LOSSLESS`, `PAGE`, `WIDTH`, `HEIGHT`, `COLORSPACE`, `ICC_PROFILE`)
and a typed `with_*()`/`*_n()` pair, so two drivers agree on the same spelling instead of inventing
their own. Everything else is a plain `with(string $key, mixed $value)` — a driver-specific knob
never requires a change to this class.

The same `ConversionOptions` instance reaches every hop of a routed, multi-step conversion
unchanged — there's no per-hop options map. A three-hop route where only the first hop understands
`PAGE` and only the last understands `QUALITY` works correctly with one shared bag: each driver reads
the keys it recognizes and ignores the rest.

**An unrecognized key is silently ignored; a recognized-but-impossible one throws.** Asking a driver
for an option it's never heard of is expected — it's probably meant for a different hop on the same
route. Asking for `colorspace=cmyk` from a driver that can only ever produce RGB is different: it
throws `UnsupportedOptionException` rather than silently returning RGB anyway, because a PDF a print
vendor expects in CMYK and receives in RGB is a correctness bug wearing a passing test, not a
cosmetic shortfall. Neither `italix/documents` nor `italix/media` can produce real CMYK today —
`ext-gd` has no CMYK support at all, and dompdf's PDF output is RGB-only — so `COLORSPACE` and
`ICC_PROFILE` are declared here without a driver yet able to honor them: the vocabulary exists so a
future Imagick-backed driver, built against a real embedded ICC profile (naive RGB→CMYK math produces
wrong print colors, which is the entire reason ICC profiles exist), has a name to read from.

## Routing

```php
use Italix\Converters\ConverterSet;
use Italix\Converters\NoRouteException;

$set = new ConverterSet([$md_to_html, $html_to_pdf]);

$set->route('md', 'pdf');     // [[md, html, $md_to_html], [html, pdf, $html_to_pdf]]
$set->convert($bytes, 'md', 'pdf');  // walks that route, feeding each hop's output into the next

try {
    $set->convert($bytes, 'md', 'mp4');
} catch (NoRouteException $e) {
    // "No route from .md to .mp4. Reachable from .md: .html, .pdf."
}
```

Three things worth knowing about how routing decides:

- **Shortest chain wins.** Every hop is a lossy re-encode, so the fewest-hop route is also the
  least-damaging one, not just the fastest one to compute.
- **Only installed drivers are reachable.** The graph is built once, at construction, from
  `is_available()` drivers only — an uninstalled Pandoc can't be "reachable" and then fail midway
  through a chain someone else is depending on.
- **`from === to` is free.** Asking to convert a format into itself returns the source untouched,
  no driver invoked.

## Grids, slices and gaps

A composite — a sprite sheet, a contact sheet, a strip of screenshots, a page of panels — is, on
each axis, a margin, then tracks separated by gutters: `margin + track + gutter + … + margin =
size`. Three stateless classes hold that geometry, with no pixels in them, so every driver that
takes a composite apart (an `Extractor`) or puts one together (a `Merger`) shares it — an image
driver today, a video mosaic or an audio timeline later:

- **`AxisLayout`** — the tracks of one axis. `resolve()` goes from a size to the tracks, `fit()`
  from what goes in each track to the size. A spec is a count (`3`), or a list of pixels and shares
  (`'200px 1fr 2fr'`); pixels must carry their unit, so `'100'` is always a count. Leftover pixels
  (1024 in three) go one each to the first shares: 342, 341, 341, in both directions.
- **`GridGeometry`** — two axes and a reading order (`GRID_ORDER`: `lr-tb`, `rl-tb`, `tb-lr`,
  `tb-rl`). `cells()` takes a sheet apart, `canvas()` puts one together, `sort()` orders any set of
  labelled cells — a detected, irregular one too.
- **`Spans`** — where the content is along one axis, from a profile (how much content each step
  holds): `content()` for the outer margin, `split()` for the gutters between. A margin around a
  scan, the silence between takes and the black frames between scenes are the same problem.

The keys (`GRID_COLUMNS`, `GRID_ROWS`, `GRID_GUTTER_X/Y`, `GRID_MARGIN_*`, `GRID_ORDER`,
`GRID_EXPECT`, `GRID_SKIP_EMPTY`, `BACKGROUND`, `GAP_MIN`, `GAP_TOLERANCE`) describe the sheet, not
the operation: the options that took a sheet apart put it back together. A piece an `Extractor`
returns carries where it came from — `ConvertedDocument::locator()`, in the driver's own shape.

## Cooperating with other libraries

Neither `italix/documents` nor a future `italix/media` needs to know the other exists. Each builds
its own `ConverterSet`, registering only the drivers relevant to its own domain — Documents'
Markdown-to-HTML, LibreOffice-backed office formats; Media's ffmpeg/ImageMagick-backed image, audio
and video formats. Cross-domain conversions (a Markdown file rendered to speech, the first page of a
PDF exported as an image) only require whoever is wiring the application together to register both
libraries' driver sets into *one* shared `ConverterSet` — routing across the boundary then falls out
of the same shortest-path search, with neither library ever importing the other.

## Cookbook

With real drivers — `italix/media`'s and `italix/documents'`, which this library does not know. So
these examples run, as written, in `italix/media`'s `tests/ConvertersCookbookTest.php`, and what each
comment says came back is asserted there.

### Which tool

| you want | use |
|---|---|
| one conversion, one driver — a resize, a format change, both | the driver itself: `$driver->convert()` |
| from format A to format Z, whatever it takes | `ConverterSet` — it finds the route |
| exactly these steps, in this order, each with its own options — the same format twice included | `Pipeline` |

`ConverterSet` and `Pipeline` take the same drivers (any `Converter`) and the same `ConversionOptions`;
what differs is that a `ConverterSet` hands **one** options bag to every hop of the route it found,
while each `Pipeline` step carries its own. `Extractor`, `Merger` and `Trimmer` belong to neither:
they change how many documents there are, not what one is.

### One driver, directly

```php
use Italix\Converters\ConversionOptions;
use Italix\Media\GdImageConverter;

$gd = new GdImageConverter();

$smaller = $gd->convert($png, 'png', 'png', ConversionOptions::none()->with_width(800));
// 1600x900 -> 800x450

$as_webp = $gd->convert($png, 'png', 'webp', ConversionOptions::none()->with_width(800)->with_quality(80));
// a resize and a format change, still one call: an 800x450 WebP
```

### A `ConverterSet`: the route is found

```php
use Italix\Converters\ConverterSet;
use Italix\Documents\HtmlToPdfConverter;
use Italix\Documents\MarkdownToHtmlConverter;
use Italix\Media\PdfPageToImageConverter;

$set = new ConverterSet([
    new MarkdownToHtmlConverter(),                 // italix/documents
    new HtmlToPdfConverter(),                      // italix/documents
    new PdfPageToImageConverter(),                 // italix/media
    new GdImageConverter(),                        // italix/media
]);

$preview = $set->convert($markdown, 'md', 'jpg', ConversionOptions::none()->with_width(400));
// image/jpeg, 400x565 — four drivers, two libraries that never import each other

$set->route('md', 'jpg');
// md->html (MarkdownToHtmlConverter), html->pdf (HtmlToPdfConverter),
// pdf->png (PdfPageToImageConverter), png->jpg (GdImageConverter)
```

### Waypoints: a format the route must pass through

```php
$set->route('png', 'jpg');             // png->jpg, direct
$set->route('png', 'jpg', ['gif']);    // png->gif->jpg: the waypoint is forced in
$set->route('md', 'jpg', ['webp']);    // ... pdf->png, png->webp, webp->jpg

$set->convert($markdown, 'md', 'jpg', ConversionOptions::none()->with_width(400), ['webp']);
// the same 400x565 JPEG, along that route

$set->all_routes('png', 'jpg', [], 1);
// every route up to one hop longer than the shortest: direct, via jpeg, via gif, via webp
```

### Driver preference: which driver, when several can

```php
$set->route('md', 'jpg', [GdImageConverter::class]);
```

A class name in `via` picks among drivers that can all do the same hop — two `pdf -> png` drivers,
say — and never adds a hop of its own. With a single candidate for every hop, as here, the route is
the one without it.

### One options bag through every hop — scoped when it must reach one driver only

```php
$set->convert($markdown, 'md', 'jpg', ConversionOptions::none()
    ->with_width(300, GdImageConverter::class)
    ->with_quality(70, GdImageConverter::class));
// a 300px-wide JPEG; no other driver on the route sees either value
```

An unscoped value reaches every hop that reads that key — a route through `GdImageConverter` twice
(`via ['webp']`) resizes twice. When each step needs its own values, that is a `Pipeline`.

### A `Pipeline`: exactly these steps

```php
use Italix\Converters\Pipeline;

// The same format twice — which no route can do — each step with its own options.
$pipeline = new Pipeline([
    ['driver' => $gd, 'options' => ConversionOptions::none()->with_width(800)],
    ['driver' => $gd, 'to' => 'webp', 'options' => ConversionOptions::none()->with_quality(70)],
    ['driver' => $gd, 'to' => 'png', 'options' => ConversionOptions::none()->with_height(200)],
]);
$pipeline->run($png, 'png');
// png -(WIDTH 800)-> png -(QUALITY 70)-> webp -(HEIGHT 200)-> png: a 356x200 PNG

// Across libraries: page 2 of a PDF, as a 600px-wide WebP.
(new Pipeline([
    ['driver' => new PdfPageToImageConverter(), 'to' => 'png', 'options' => ConversionOptions::none()->with_page(2)],
    ['driver' => $gd, 'to' => 'webp', 'options' => ConversionOptions::none()->with_width(600)],
]))->run($pdf, 'pdf');
```

### Geometry without pixels

```php
use Italix\Converters\AxisLayout;
use Italix\Converters\GridGeometry;
use Italix\Converters\Spans;

AxisLayout::resolve(1024, 3, 0, 0, 0);
// [[0, 342], [342, 341], [683, 341]] — [offset, size] of each track

GridGeometry::cells(300, 200, ConversionOptions::none()->with_grid_columns(3)->with_grid_rows(2))[4];
// ['row' => 2, 'column' => 2, 'x' => 100, 'y' => 100, 'width' => 100, 'height' => 100]

GridGeometry::canvas([[100, 80], [120, 60], [100, 80]], ConversionOptions::none()->with_grid_columns(2));
// a 220x160 sheet, and the cell each piece goes into

Spans::split([0.0, 0.0, 0.4, 0.9, 0.0, 0.0, 0.0, 0.7, 0.8, 0.0], 2);
// [[2, 3], [7, 8]] — the runs of content, split where at least 2 empty steps lie between
```

### When it cannot work, it says so

```php
(new ConverterSet([new GdImageConverter()]))->convert($png, 'png', 'docx');
// NoRouteException: No route from .png to .docx. Reachable from .png: .jpg, .jpeg, .gif, .webp.

(new Pipeline([['to' => 'webp']]))->run($png, 'png');
// ConversionException: Pipeline step 0 needs a 'driver' key holding a Converter instance.
```

## Verification

34 assertions in `tests/ConverterSetTest.php`, run with:

```bash
composer test
```

All against a `FakeConverter`/`OptionsAwareFakeConverter` test double rather than a real driver — the
routing logic (shortest path, availability filtering, first-registered-wins tie-breaking, byte
threading across hops, `ConversionOptions` reaching every hop unchanged) is what this library
actually owns, and it needs no external binary to verify. Every branch was mutation-tested by hand
while writing this suite, and one of those mutations (breadth-first search weakened to a
stack-ordered traversal) exposed that the first version of the "shortest path wins" test was too
weak to tell BFS apart from a bug — it happened to find a direct edge on the very first expansion
regardless of traversal order. The test that replaced it is deliberately adversarial: a 2-hop route
registered first, next to a 3-hop detour that a naive "explore what you just pushed" search would
return instead.

What isn't covered here, by construction: real drivers, real external binaries, and cross-library
routing. That's `italix/documents`' and `italix/media`'s own test suites — in particular
`italix/media`'s `tests/PdfPageToImageConverterTest.php`, which proves the full `md → html → pdf →
png` chain across both libraries against real dompdf and Ghostscript calls.

## License

Mozilla Public License 2.0 — see `LICENSE`.
