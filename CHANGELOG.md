# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The public interface of this package is the `depone` CLI: the command name,
options, exit codes, and command output. PHP classes under `src/` are internal.

## [Unreleased]

### Added

- Every finding now carries its evidence, and every `require_once` is accounted
  for. Requires that are legitimately not autoloadable are no longer silently
  unreported: they form a fourth category, `needed`, with the reason the require
  stays load-bearing (kept out of the default text output, which is unchanged).
- `--format=json`: machine-readable output carrying the full evidence — per
  redundant finding the proof (each declared class with the autoload mechanism
  and resolved path, or the eager `autoload.files` fact), per fixable/conflicting
  finding the class and path involved, plus the `needed` section and a coverage
  summary. The document carries a `schema_version` (currently `1`).
- `--explain`: human-readable variant of the text output that prepends a
  coverage header (`includes_total`/`resolved`/`unresolved`/`needed_require_once`)
  and prints the autoload evidence under each redundant finding. Not a frozen
  format, unlike the default text output.
- `--verify`: cross-checks every redundant finding against the autoload maps
  Composer actually dumped under `vendor/composer/`, using Composer's own
  `ClassLoader` (never executing project code). Mismatches — a stale
  `composer dump-autoload` or a depone resolution bug — are reported in a
  trailing `verify_mismatches` section (or a `verify` block in JSON) and count
  as findings for the exit code. Exits `2` with guidance when no dumped maps
  are present.

### Changed

- **Breaking:** exit codes now distinguish findings from failures, so depone
  can gate CI. `0` = the analysis ran and found no redundant, fixable, or
  conflicting require; `1` = at least one was reported (previously `0`);
  `2` = the analysis could not run, including invalid invocations
  (previously `1`). `unresolved_include_require` entries and `--trace` output
  never affect the exit code. To keep a CI step green on findings, use
  `vendor/bin/depone || [ $? -ne 2 ]` (ignores findings but still fails when
  the analysis could not run); a plain `|| true` also masks execution errors.
- Internal: the actionable finding categories (`redundant`, `fixable`,
  `conflicting`) are now defined once and shared by the exit-code gate and the
  summary output. No behavior change.

## [0.2.1] - 2026-07-05

### Changed

- The package is now published on Packagist as **`depone/depone`** (previously
  `lll-lll-lll-lll/depone`), and the repository was renamed to
  `lll-lll-lll-lll/depone`. Install with `composer require --dev depone/depone`;
  the old package name is abandoned in favor of the new one.
- Lowered the minimum supported PHP version from 8.4 to 8.1, and widened the
  accepted symfony/console range to `^6.4 || ^7.0 || ^8.0`, so depone can be
  installed as a dev dependency in the legacy projects it is built for.

### Fixed

- The CLI no longer crashes on startup when installed under the new
  `depone/depone` package name (the version lookup still queried the old
  package name, which throws when that package is not installed).

## [0.2.0] - 2026-07-05

### Added

- Classify `require_once` statements whose targets are *not* autoload-reachable,
  in the default text output. Beyond the existing `redundant_require_once`
  section, two new sections are reported:
  - `fixable_require_once`: the target declares a class whose PSR-4/PSR-0 rule
    matches but whose derived path does not exist. The require is a crutch —
    fix the autoload config and it can be removed.
  - `conflicting_require_once`: the target declares a class that autoload
    resolves to a *different* file (a shadowed copy). Deleting the require would
    change which definition loads, so it is flagged as a hazard rather than a
    simple removal.
  Requires that are legitimately not autoloadable (no matching rule, the target
  declares no types, or the target also carries functions/constants/side
  effects) remain unreported. This join of `require_once` statements against
  autoload reachability is not something `composer dump-autoload --strict-psr`/
  `--strict-ambiguous` can report, since Composer never parses source-level
  require statements.

### Changed

- Class detection now uses nikic/php-parser, which becomes a runtime dependency.

### Fixed

- `redundant_require_once` no longer flags a require that is not actually safe to
  delete. Previously a require was reported redundant as soon as *any* class in
  the target autoloaded back to it; a require is now reported only when deleting
  it provably changes nothing:
  - the target is an `autoload.files` entry (Composer loads those eagerly, so the
    require is a no-op), or
  - the target declares only types (no functions, constants, or top-level side
    effects — autoload reproduces none of those) and *every* declared class
    autoloads back to that same file.

  A target with a class that autoloads from a different file (a shadowed copy),
  with an unreachable sibling class, or that also defines a function/constant is
  therefore no longer called redundant.

## [0.1.0] - 2026-07-04

### Added

- Detect redundant `require_once` statements whose targets are already covered
  by Composer autoload (`psr-4`, `psr-0`, `classmap`, `files`, including
  `autoload-dev`).
- Report include/require statements whose path expressions cannot be resolved
  statically, with a reason classification.
- `--trace` option: show reverse caller traces (which files require the given
  file, and from which entrypoints).

[Unreleased]: https://github.com/lll-lll-lll-lll/depone/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/lll-lll-lll-lll/depone/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/lll-lll-lll-lll/depone/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/lll-lll-lll-lll/depone/releases/tag/v0.1.0
