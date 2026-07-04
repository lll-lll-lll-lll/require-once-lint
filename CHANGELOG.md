# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The public interface of this package is the `depone` CLI: the command name,
options, exit codes, and command output. PHP classes under `src/` are internal.

## [Unreleased]

### Added

- Detect redundant `require_once` statements whose targets are already covered
  by Composer autoload (`psr-4`, `psr-0`, `classmap`, `files`, including
  `autoload-dev`).
- Report include/require statements whose path expressions cannot be resolved
  statically, with a reason classification.
- `--trace` option: show reverse caller traces (which files require the given
  file, and from which entrypoints).

[Unreleased]: https://github.com/lll-lll-lll-lll/require-once-lint/commits/main
