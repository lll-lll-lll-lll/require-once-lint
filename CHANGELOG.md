# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The public interface of this package is the `depone` CLI: the command name,
options, exit codes, and command output. PHP classes under `src/` are internal.

## [Unreleased]

## [0.4.0] - 2026-07-12

### Added

- Dependency-aware autoload resolution. When Composer has dumped its autoloader
  (`vendor/composer/autoload_*.php` present), depone classifies each
  `require_once` against the merged root + dependency autoload maps, exactly as
  Composer resolves them at runtime — so a require that loads a shadowed copy of
  a dependency-provided class is reported as `conflicting`, and a require of a
  dependency's eager `autoload.files` entry is reported as `redundant`. Without a
  dumped autoloader depone falls back to reading the root `composer.json`, as
  before.
- `--format json` emits the report (and `--trace` output) as JSON for machine
  consumption — CI dashboards, editors, `jq`. Exit codes are unchanged and
  `text` remains the default format.
- `--inventory` lists the kept (load-bearing) `require_once` targets with the
  reasons they cannot be removed: the top-level side effects autoload would
  not reproduce (`define()`, `ini_set()`, function definitions, global
  assignments, ...) as `kind:line` rows with a one-line excerpt, classes that
  do not autoload back to the target, and guarded-only (polyfill)
  declarations. One entry per target, with how many files require it. The
  inventory is informational like `--trace` — it always exits `0` unless the
  analysis fails — and honors `--format json`. Targets under `vendor/` are
  excluded.

### Changed

- Internal: class-name-to-file resolution is now performed by Composer's own
  `ClassLoader`, and the composer.json `classmap` fallback scans files with
  [composer/class-map-generator](https://github.com/composer/class-map-generator)
  (a new runtime dependency) — the same code `composer dump-autoload` runs —
  instead of hand-written re-derivations of both. No behavior change.
- Include/require path expressions are now evaluated with php-parser's
  constant-expression evaluator instead of a hand-written expression parser.
  A few more statically-constant expressions resolve than before (numeric
  literals in concatenations such as `'v' . 1 . '/api.php'`, `\dirname()`,
  and `define()`'d constants that share a function's name); everything else
  is unchanged.

### Fixed

- `--format json` no longer collapses the whole report to `{}` when an analyzed
  source contains invalid UTF-8 (e.g. a Latin-1 legacy file whose bytes reach
  the report through an unresolved `expr`): invalid sequences are substituted
  with U+FFFD and every finding survives.

## [0.3.0] - 2026-07-11

### Removed

- **Breaking:** dropped the `fixable_require_once` section. Whether a class's
  PSR-4/PSR-0 rule derives a path that actually exists is autoload-config
  validation — already covered by `composer dump-autoload --strict-psr` — and
  drifts from depone's one job: relating each `require_once` to what autoload
  actually loads. A require whose target is not autoload-reachable is now left
  unreported (it is load-bearing today), the same as any other non-autoloadable
  target. depone now reports two outcomes for a resolved `require_once`:
  `redundant` (provably safe to delete) and `conflicting` (loads a shadowed
  copy).

### Changed

- **Breaking:** exit codes now distinguish findings from failures, so depone
  can gate CI. `0` = the analysis ran and found no redundant or conflicting
  require; `1` = at least one was reported (previously `0`);
  `2` = the analysis could not run, including invalid invocations
  (previously `1`). `unresolved_include_require` entries and `--trace` output
  never affect the exit code. To keep a CI step green on findings, use
  `vendor/bin/depone || [ $? -ne 2 ]` (ignores findings but still fails when
  the analysis could not run); a plain `|| true` also masks execution errors.
- Internal: the actionable finding categories (`redundant`, `conflicting`) are
  now defined once and shared by the exit-code gate and the summary output.
  No behavior change.

### Fixed

- classmap duplicate classes now break ties the way Composer does — the first
  occurrence wins, over a deterministic scan order — instead of letting the
  last-scanned file win in raw filesystem order. Previously a require of the
  shadowed copy could be reported `redundant` (safe to delete) when its true
  category is `conflicting`.
- guarded/conditional declarations (a polyfill behind `if (!class_exists())`)
  are no longer reported as a false `conflicting`: the guard makes the require
  idempotent, so nothing is shadowed. Such a require is load-bearing, so it is
  left unreported like any other non-autoloadable target.
- a require target reached through a symlink is no longer reported as
  `conflicting` when it resolves to the same file autoload would load.

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

[Unreleased]: https://github.com/lll-lll-lll-lll/depone/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/lll-lll-lll-lll/depone/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/lll-lll-lll-lll/depone/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/lll-lll-lll-lll/depone/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/lll-lll-lll-lll/depone/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/lll-lll-lll-lll/depone/releases/tag/v0.1.0
