# Changelog — italix/converters

Format: [Keep a Changelog](https://keepachangelog.com/). Versioning policy: `VERSIONING.md` at the
project root.

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
