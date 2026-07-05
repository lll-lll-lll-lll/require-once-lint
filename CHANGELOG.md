# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The public interface of this package is the `depone` CLI: the command name,
options, exit codes, and command output. PHP classes under `src/` are internal.

## [Unreleased]

### Added

- `doctor` subcommand: reports files and classes that the Composer autoloader
  can never reach — classes shadowed by another autoload winner
  (`resolved_elsewhere`), classes whose namespace maps to a path that does not
  exist (`expected_path_missing`), classes matching no autoload rule
  (`no_matching_rule`), and candidate files that declare no types
  (`no_declarations`). Findings are grouped into `error`/`warning`/`info`
  sections. By default only the `error` section is printed, since warnings and
  info are frequently fixture-driven noise; widen the output with
  `--min-severity=warning` (adds warnings) or `--min-severity=info` (adds all
  three sections). Text output only; always exits 0.

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
