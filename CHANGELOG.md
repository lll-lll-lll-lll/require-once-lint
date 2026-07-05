# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The public interface of this package is the `depone` CLI: the command name,
options, exit codes, and command output. PHP classes under `src/` are internal.

## [Unreleased]

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

[Unreleased]: https://github.com/lll-lll-lll-lll/require-once-lint/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/lll-lll-lll-lll/require-once-lint/releases/tag/v0.1.0
