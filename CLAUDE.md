# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`depone` is a PHP CLI static-analysis tool that classifies every `require_once` in a Composer project by its relationship to Composer autoload: **redundant** (target already autoloaded; deleting is provably safe), **conflicting** (target shadows a copy the autoloader would load from a different file), or unresolved/unreported. It is report-only and never rewrites code.

## Commands

```sh
composer install       # setup
composer check         # everything CI runs locally: cs + analyse + test
composer test          # PHPUnit
composer analyse       # PHPStan (level max, covers src/ and bin/)
composer cs            # PHP-CS-Fixer dry-run (PSR-12)
composer cs-fix        # apply style fixes
```

Run a single test file or test:

```sh
vendor/bin/phpunit tests/AnalyzerTest.php
vendor/bin/phpunit --filter testMethodName
```

Run the tool itself: `php bin/depone` (options: `--trace <repo-relative-path>`, `--format json`).

Supported PHP range is 8.1–8.5; CI tests the full matrix including lowest deps, so avoid syntax/APIs newer than 8.1.

## Architecture

The pipeline, from `bin/depone`:

1. **`Cli\CliApplication`** wires Symfony Console around the single **`Cli\FindRedundantCommand`** (command name `depone`). Exit codes are the public contract: `0` clean, `1` findings, `2` analysis could not run. They gate on `Analyzer::ACTIONABLE_CATEGORIES` (`redundant`, `conflicting`); `unresolved` and `edges` are informational only.
2. **`Core\Analyzer`** walks every PHP file (excluding `vendor/`, `.git/`), finds include/require statements by tokenizing with `token_get_all()`, and produces the `AnalysisResult` array shape (see its phpstan types).
3. **`Tokenizer\IncludeExprParser`** evaluates each include's path expression using php-parser's `ConstExprEvaluator`, extended with context (`__DIR__`/`__FILE__`, `define()`'d constants, `dirname()`). Unevaluable expressions become `unresolved` entries with a reason (`variable`, `method_call`, `static_access`, `complex`) — never silently dropped.
4. **`Tokenizer\DeclaredClassExtractor`** parses each resolved target with php-parser to find the class-like types it declares and whether it declares *only* types (no functions/constants/top-level code).
5. **`Resolver\AutoloadResolver`** resolves class names to files with Composer's own `ClassLoader`. It prefers the dumped `vendor/composer/autoload_*.php` maps; without them it falls back to reading the root `composer.json` (psr-4/psr-0/classmap/files + dev), using `composer/class-map-generator` for classmap scanning.
6. Classification: redundant only when provably safe (eager `autoload.files` entry, or target declares nothing but types and every declared class autoloads back to that same file); a class resolving to a *different* file makes it conflicting; anything else is unreported (the require is load-bearing).
7. **`Core\OutputFormatter`** renders text/JSON; **`Core\DependencyGraph`** builds reverse caller traces for `--trace` from the collected edges.

A key design rule (documented in the README and past PRs): **delegate to Composer and php-parser machinery instead of reimplementing it** — class-to-file resolution goes through Composer's `ClassLoader`, expression evaluation through php-parser's `ConstExprEvaluator`, classmap scanning through `composer/class-map-generator`.

## Public interface and conventions

- The supported public interface is the CLI only: command name, options, exit codes, and output. Everything under `src/` is `final`, marked `@internal`, and carries no BC guarantee.
- Any change to the user-visible CLI (options, exit codes, output) needs a one-line entry under **Unreleased** in `CHANGELOG.md`.
- Findings exit `1` and errors exit `2` deliberately, so CI consumers can distinguish "findings" from "analysis failed" — don't collapse them.

## Tests

Tests live flat in `tests/`; `tests/Fixture/` holds self-contained mini Composer projects (each with its own `composer.json`) that tests run the analyzer against, and is excluded from the PHPUnit test suite. To cover a new classification scenario, add or extend a fixture project rather than mocking the filesystem.
