# Changelog — italix/converters

Format: [Keep a Changelog](https://keepachangelog.com/). Versioning policy: `VERSIONING.md` at the
project root.

## [0.7.0] — 2026-09-26

### Changed

Postfixes that say what a value is: `_n` only for a count, `_i` for an index, `_perc` for a
percentage from 0 to 100, none for a level.

- **`quality()`** reads `QUALITY`, **`page_i()`** reads `PAGE` (1-based: an index). `quality_n()`
  and `page_n()`, published in 0.3.0, stay as **deprecated aliases** returning the same, to be
  removed in 0.8.0.
- **`align_x_perc()`, `align_y_perc()`** read `ALIGN_X`/`ALIGN_Y`. `align_x_n()`/`align_y_n()`,
  published in 0.5.0, stay as **deprecated aliases** too, removed in 0.8.0.
- **`gap_tolerance_perc()`** reads `GAP_TOLERANCE` — renamed outright: `gap_tolerance_n()` (0.6.0)
  was never published.
- The setters' parameters follow (`with_quality(int $quality)`, `with_page(int $page_i)`,
  `with_align_x(int $align_perc)`, `with_gap_tolerance(int $tolerance_perc)`): a caller passing them
  **by name** (`with_quality(quality_n: 80)`) has to rename the argument — the one change here no
  alias can cover. Positional calls are unaffected.

`tests/ConversionOptionsTest.php` (16) reads every renamed value by its new name and through both
aliases; the suites written against 0.3.0's names pass unchanged, through the aliases.

The seven suites run on the shared runner (`Italix\Testing`), so `ix test` sees them: `italix/testing` `^2.0` is
a dev dependency. Their checks are unchanged — the same 183.



### Added

**Grids, slices and gaps** — the geometry a composite is taken apart and put together with, shared by
every driver that does either.
All three stateless, never instantiated, no pixels — like `CropGeometry`.

- **`AxisLayout`** — tracks along one axis: `margin + track + gutter + … + margin = size`. `resolve()`
  from a size to the tracks, `fit()` from what goes in each track to the size. A spec is a count
  (`3`, or `'3'`) or a list of pixels and shares (`'200px 1fr 2fr'`). **Pixels carry their unit**:
  the first merger test found `'100'` read two ways (a hundred tracks, or one of 100px), so a bare
  number is always a count. No percentages in a list — `'1fr 3fr'` says `'25% 75%'` and adds up by
  construction. Leftover pixels go one each to the first shares (1024 in three: 342, 341, 341), in
  both directions. Without an `fr`, the pixel tracks must add up exactly, or it throws. `fit()` fits
  what it is given — a shared track is as large as its content, not stretched to its weight — and an
  empty shared track (a declared grid not full) is as large as the largest one, so empty cells are
  background, not a collapsed line. Counts above `MAX_TRACKS_N` (10000) and axes that would overflow
  an integer are refused before anything is allocated.
- **`GridGeometry`** — two `AxisLayout`s and a reading order (`lr-tb`, `rl-tb`, `tb-lr`, `tb-rl`).
  `cells()` takes a sheet apart, `canvas()` puts one together (percentages refused there: a share of
  the size being worked out is circular), `sort()` orders any set of labelled cells, a detected
  irregular one too. `'auto'` is refused: detection needs pixels, a driver's job.
- **`Spans`** — where the content is along one axis, from a profile: `content()` for the outer margin,
  `split()` for the gutters at least `min_gap` long. An image's margins, a recording's silences and a
  video's black frames are the same problem.

`ConversionOptions` — fourteen keys, each with its typed `with_*()` and reader: `GRID_COLUMNS`,
`GRID_ROWS`, `GRID_GUTTER_X/Y`, `GRID_MARGIN_LEFT/RIGHT/TOP/BOTTOM`, `GRID_ORDER`, `GRID_EXPECT`,
`GRID_SKIP_EMPTY`, `BACKGROUND`, `GAP_MIN`, `GAP_TOLERANCE`. They describe the sheet, not the
operation: the options that take a sheet apart put it back together.

`ConvertedDocument` — an optional fourth constructor argument and `locator()`: where a piece came
from, in its driver's own shape. Every existing `new ConvertedDocument(…)` is unchanged.

### Verification

`tests/AxisLayoutTest.php` (39), `tests/GridGeometryTest.php` (32), `tests/SpansTest.php` (12) —
worked examples computed by hand before the code, as `CropGeometryTest` was. An adversarial review
fuzzed the geometry (20,000 random `cells()` → `canvas()` round trips: in bounds, no overlap, the same
sheet back; 20,000 random `split()` profiles) and mutation-tested it (84 mutants); what it found — the
overflows, the empty tracks, the whitespace — is fixed and pinned by a test that fails without the
fix. PHPStan level 6 clean on the new sources.

## [0.5.0] — 2026-09-03

### Added

**`CropGeometry`** — resolves `ConversionOptions`' new crop-geometry keys (`WIDTH`, `HEIGHT`, `RATIO`,
`ALIGN_X`, `ALIGN_Y`, `CROP_LEFT`, `CROP_RIGHT`, `CROP_TOP`, `CROP_BOTTOM`) against a source's real
pixel dimensions into a concrete crop box. Deliberately a stateless utility, never instantiated — not
a value object a caller constructs and passes around (an earlier draft of this feature, `RatioCrop`,
was exactly that, and was dropped in favor of a flat options surface instead).

The core rule, applied independently to each axis: `crop_start + target_size + crop_end = source_size`
ties three quantities together, which is two degrees of freedom, not three independent knobs. Any 2
of the 3 given solves the third directly; 1 given (just a target size) needs `ALIGN_X`/`ALIGN_Y` to
decide where the cut falls; 0 given leaves that axis untouched; all 3 given must already agree or it
throws, rather than silently preferring one. `CROP_LEFT`/`CROP_RIGHT`/`CROP_TOP`/`CROP_BOTTOM` accept
a plain int (pixels) or a percentage string (`'10%'`), resolved to pixels before the equation runs.
`RATIO` fills in whichever axis has no direct information of its own; when neither axis has direct
information, the axis that would need to *grow* to hit the ratio is kept full-size instead, and the
other is cropped down — a ratio only ever removes content here, the same as everything else in this
class. When both axes are already fully resolved, `RATIO` becomes a consistency check (within 1px for
rounding) rather than a silent override.

22 assertions in `tests/CropGeometryTest.php`, each one a worked example designed by hand *before* the
class was written, specifically so the tests would be checking the code against an independent
expectation rather than one derived from the code itself — including the concrete case that motivated
the whole feature: `CROP_LEFT=100, CROP_RIGHT=100` on a 1000px axis and `WIDTH=800` (centered) on the
same axis are proven to produce the byte-identical crop box, not just "similar" results.

### Changed — `ConversionOptions`

Seven new keys (`RATIO`, `ALIGN_X`, `ALIGN_Y`, `CROP_LEFT`, `CROP_RIGHT`, `CROP_TOP`, `CROP_BOTTOM`),
each with a typed `with_*()`/read pair, following the same shared-key reasoning as `WIDTH`/`HEIGHT`/
`QUALITY` — promoted straight to shared rather than starting driver-local, because the concept (crop a
2-D frame) is already known to apply identically to a still image and a video's per-frame geometry,
not discovered as a second need later.

## [0.4.0] — 2026-09-03

### Added

Three new interfaces, alongside `Converter`, for cardinalities `Converter` never covered:

- **`Extractor`** — 1 source → N pieces, same format. Covers both "give me your default partition"
  (`$boundaries = null`: every page, a uniform grid via a driver's own `ConversionOptions` keys, or a
  driver's own built-in content-detection default) and "give me exactly these regions"
  (an explicit, arbitrary, not-necessarily-exhaustive locator list — a driver's own shape: a page
  range, a pixel bounding box, a time range). A single explicit boundary covers what would otherwise
  need a separate "pull just one piece" interface — there wasn't a real second cardinality once
  boundaries could be supplied explicitly, so that idea was folded in here rather than shipped
  alongside it.
- **`Trimmer`** — 1 source → 1 (shorter) source, same format. Distinct from `Extractor`: always
  exactly one result, and only the outer edges are ever touched. `$amount = null` means "decide
  adaptively" (detect real content bounds, silence, blank frames); an explicit `$amount` is the
  driver's own shape, symmetric or not. Cropping to a target aspect ratio is not a third mode — it is
  just another way of computing an explicit `$amount` before calling `trim()`.
- **`Merger`** — the inverse of `Converter`: N sources → 1 result, `pairs()` shaped identically to
  `Converter::pairs()`, just inverted. No `MergerSet` — merging doesn't compose through intermediate
  formats the way `ConverterSet` chains `Converter` hops, so a direct pair lookup is all it needs.

**`Pipeline`** — an explicit, ordered list of `Converter` steps, each with its own independent
`ConversionOptions`. The deliberate counterpart to `ConverterSet`: `ConverterSet` answers "find the
best way from format A to format Z automatically"; `Pipeline` answers "run exactly these operations,
in exactly this order, because I decided that." Exists because `ConverterSet` structurally cannot
express some real sequences — its graph nodes are formats, and `route()` only ever returns a simple
path, so the same format can never appear twice in one route. A denoise → upscale → sharpen chain is
three `png → png` steps in a row; no shortest-path search can produce that, because "shortest" stops
meaning anything once a node can repeat. Scoped to `Converter` steps only for now — a step that forks
into N pieces (an `Extractor`) turns a linear pipeline into a fan-out DAG, a bigger problem with no
concrete shape proven yet; composing that by hand (`extract()`, then a `Pipeline` per resulting piece)
works today without `Pipeline` needing to grow to cover it speculatively.

### Changed — `ConverterSet`

- **The graph now keeps every available driver per edge, not just the first-registered one.**
  Previously `build_graph()` discarded every driver but the first for a given `(from, to)` pair at
  construction time — there was nothing left for a caller to choose between. Unconstrained resolution
  still defaults to the first registered, exactly as before: fully backward compatible, verified by
  re-running every existing assertion unchanged (34/34) before adding anything new.
- **`route()`, `convert()` and the new `all_routes()` all gain a `via` parameter** — an ordered list
  whose entries are either a format extension (a mandatory waypoint node, forced even if a shorter
  route would skip it) or a driver's class name (an edge preference — wins the tie-break for
  whichever hop it can serve, wherever that hop ends up, but cannot insert a hop the shortest path
  wouldn't otherwise include). An entry is resolved as a format if it names one of the extensions
  actually present in the graph; otherwise it is treated as a driver class name. A `via` entry
  matching neither has no effect, rather than throwing — the same "unrecognized key is silently
  ignored" stance `ConversionOptions` already takes.
- **`all_routes()`** — every route tied for the shortest hop count (honoring `via`), in every driver
  combination available at each hop, for exploring what `convert()` *could* do before pinning one
  choice down with `via`. Never used internally by `convert()`, which stays deterministic. `$slack_n`
  widens the search to routes up to that many hops longer than the shortest. Implemented as a bounded
  depth-first search from the source, not an extension of `route()`'s plain BFS distances — a first
  attempt tried to reuse BFS's per-node shortest-distance tracking to also find "moderately longer"
  paths, and it was wrong: a node's BFS distance is only ever *the* shortest distance to it, which
  says nothing about a second, slightly longer way to reach it. Caught by hand-tracing the algorithm
  against a small graph before writing the test, not by the test itself failing — worth being honest
  that the bug was found before it shipped, not after.

### Changed — `ConversionOptions`

**Driver-scoped values.** Every `with_*()` accepts an optional trailing driver class name; every
read accepts an optional trailing reader class name. Unscoped (the default, omitting both) behaves
exactly as before — a value set with no driver name reaches every hop, and every driver that
recognizes the key uses it. A value set *with* a driver name is invisible to every other reader; only
a driver naming itself on the read sees it. Solves a real collision the flat shared bag cannot: two
different drivers reading the same key with different meanings (`GdImageConverter`'s `QUALITY` is a
0-100 JPEG/WebP setting; a hypothetical AI-upscale driver's `QUALITY` could mean a 1-5 fidelity
preset) — scoping one to a specific driver removes the collision without either driver needing a
differently-spelled key. Deliberately identity-scoped, not position-scoped (an earlier draft of this
change used a `per_hop['from:to']` map instead): a route position requires the caller to already know
the resolved route's internal shape, which is exactly the routing-internals leak `via`'s driver
preferences were designed to avoid; driver identity does not, and unlike a route position, stays
meaningful even when the same driver runs via two entirely separate `convert()`/`Pipeline` calls.

### Verification

62 assertions across `tests/ConverterSetTest.php` (53, up from 34 — every existing assertion still
passes unchanged, plus new coverage for the multi-driver graph, `via` with formats and driver names
together, `all_routes()` with and without slack, and driver-scoped `ConversionOptions` disambiguating
a real key collision) and the new `tests/PipelineTest.php` (9, including the literal
denoise → upscale → sharpen → compress, `png → png → png → png → webp` case this was built for).
`libs:check`/`encode:lint`/`phpstan` level 6 clean.

## [0.3.0] — 2026-08-28

### Changed — BREAKING

`_c` on function/method names is retired in favor of spelling out what the value actually is —
see `src/Libs/Italix/CONVENTIONS.md`, "`_c` is for variables... only." `_c` stays on variables,
parameters and properties; only method names changed, no behavior. Recorded as MINOR, not MAJOR,
for the same reason as `[0.2.0]`: this library stays 0.x deliberately, so a breaking change within
it is not disguised as a stability claim it hasn't earned:

- `ConvertedDocument::extension_c()` → `extension_code()`, `mime_c()` → `mime()` (MIME type is the
  established term, not "MIME code")
- `ConversionOptions::colorspace_c()` → `colorspace_code()`
- `NoRouteException::from_extension_c()`/`to_extension_c()` → `from_extension_code()`/
  `to_extension_code()`, `reachable_extensions_c()` → `reachable_extensions()` (it returns an
  array — the collection itself isn't "a code")
- `UnsupportedOptionException::option_key_c()` → `option_key_code()`, `driver_description_c()` →
  `driver_description()` (a full sentence from `describe()`, not a code)
- `ConversionException::driver_stderr_c()` → `driver_stderr()` (raw process output, not a code)

## [0.2.0] — 2026-08-27

### Added

- **`ConversionOptions`** — a per-call bag of knobs (`QUALITY`, `LOSSLESS`, `PAGE`, `WIDTH`,
  `HEIGHT`, `COLORSPACE`, `ICC_PROFILE`), threaded unchanged through every hop of a routed
  conversion. A handful of well-known keys get a named constant and a typed `with_*()`/`*_n()` pair,
  so two unrelated drivers agree on the same spelling for "quality" instead of inventing their own;
  everything else goes through the generic `with()`/`get()`, so a driver-specific knob never requires
  a new method on this class. `COLORSPACE`/`ICC_PROFILE` are declared even though nothing in this
  codebase can honor them yet (`ext-gd` has no CMYK support at all; dompdf is RGB-only) — the
  vocabulary exists so a driver that can, later, has a name to read from.
- **`UnsupportedOptionException`** — thrown by a driver that recognizes a requested option but
  cannot do it (a colorspace it cannot produce), as distinct from an option key it has simply never
  heard of, which is silently ignored (see `ConversionOptions`' own docblock for why). Carries the
  key, the rejected value, and the failing driver's own `describe()`.

### Changed — BREAKING

- **`Converter::convert()` gained a required fourth parameter, `ConversionOptions $options`.** Every
  driver implementing this interface must update its signature. Per `VERSIONING.md`'s table ("New
  required parameter | MAJOR"), this is a MAJOR-classified change — recorded as a MINOR bump within
  the 0.x series rather than a jump to `1.0.0`, for the same reason `italix/documents` stayed 0.x
  through its own MAJOR-classified change: declaring `1.0.0` in the same changeset that breaks the
  interface would say "stable" the moment it demonstrably wasn't. `ConverterSet::convert()` itself
  keeps a nullable, defaulted fourth parameter (`ConversionOptions::none()` when omitted), so existing
  three-argument call sites through the router are unaffected — only direct calls to a `Converter`
  implementation's own `convert()` need the new argument.
- This was possible to do as a clean break, not a deprecate-and-migrate per house rule 15, because
  nothing outside this session's own test suites and the two consuming libraries (`italix/documents`,
  `italix/media`) called this interface yet — the explicit reason this redesign was done now rather
  than deferred.

## [0.1.0] — 2026-08-27

### Added

First slice: the `Converter` contract (`pairs()`, `is_available()`, `convert()`, `describe()`),
`ConvertedDocument` (bytes + extension + mime), `ConversionException` (carries a driver's stderr
when there is one), and `ConverterSet` — a shortest-path router over registered, available drivers.

24 assertions in `tests/ConverterSetTest.php`, all against a fake in-memory driver — no real
converter driver exists yet, so nothing here has been run against Pandoc, LibreOffice, or ffmpeg.
Every branch mutation-tested by hand: the availability filter, the first-registered-wins tie-break,
the identity short-circuit, and the breadth-first shortest-path search itself (which needed a
second, adversarial test graph after the first one turned out unable to tell BFS apart from a
broken stack-ordered traversal — see `README.md` "Verification").

### Not included yet

Any real `Converter` implementation (Pandoc, LibreOffice headless, ffmpeg, or otherwise) — this
library is the plumbing `italix/documents` and a future `italix/media` will each register drivers
into, not a source of drivers itself. Mime-type resolution for the identity conversion path is also
an open point, noted inline in `ConverterSet::convert()` — it probably belongs to `italix/storage`'s
`MimeCatalog` rather than a second one invented here.
