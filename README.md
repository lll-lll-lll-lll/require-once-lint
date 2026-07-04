# depone

[![CI](https://github.com/lll-lll-lll-lll/require-once-lint/actions/workflows/ci.yml/badge.svg)](https://github.com/lll-lll-lll-lll/require-once-lint/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/lll-lll-lll-lll/depone)](https://packagist.org/packages/lll-lll-lll-lll/depone)
[![PHP Version](https://img.shields.io/packagist/dependency-v/lll-lll-lll-lll/depone/php)](https://packagist.org/packages/lll-lll-lll-lll/depone)
[![License](https://img.shields.io/packagist/l/lll-lll-lll-lll/depone)](LICENSE)

> *Rerum curam depone.* — Lay down your worries.

A static analysis tool for PHP that helps you eliminate require_once-related
issues.

Legacy PHP projects accumulate `require_once` statements over the years. After
Composer autoload is introduced, many of them become redundant — but judging
which ones by hand is impractical. `depone` tokenizes every PHP file in the
repository, statically resolves each `require_once` target, and reports the
ones whose targets Composer already autoloads.

`depone` is a CLI tool. Its supported public interface is the command name,
options, exit codes, and command output. PHP classes under `src/` are internal
implementation details and may change without backward compatibility guarantees.

## Requirements

- PHP 8.4 or newer
- Composer

## Installation

```sh
composer require --dev lll-lll-lll-lll/depone
```

## Usage

Run the analyzer from the root of a Composer project:

```sh
vendor/bin/depone
```

```
redundant_require_once=2
public/index.php:5 => src/Foo.php
src/Legacy/Bootstrap.php:12 => src/Util/Path.php

unresolved_include_require=1
  public/plugin.php:8 [variable] $pluginDir . '/init.php'
```

Each redundant line means: the file at `file:line` has a `require_once` whose
target is already autoloadable, so the statement can likely be removed.

Include/require statements whose path expression cannot be resolved statically
are never silently skipped — they are listed under
`unresolved_include_require` with a reason:

| Reason | Meaning |
| --- | --- |
| `variable` | the expression contains a variable |
| `method_call` | the expression contains an object method call |
| `static_access` | the expression contains `::` access |
| `complex` | anything else the evaluator cannot resolve |

Before deleting a `require_once`, check who requires the file and from which
entrypoints:

```sh
vendor/bin/depone --trace src/Foo.php
```

```
trace_target=src/Foo.php
direct_callers=1
  - public/index.php
entrypoint_candidates=1
  - public/index.php
trace_paths=1
  1. public/index.php -[r]-> src/Foo.php
```

The exit code is `0` on success and `1` on failure (for example, when
`composer.json` cannot be read).

## How it works

1. Collects the set of autoloadable files from `composer.json` (`psr-4`,
   `psr-0`, `classmap`, and `files`, including `autoload-dev`). For PSR rules,
   a file only counts when a class it declares actually resolves back to that
   file.
2. Tokenizes every PHP file (excluding `vendor/` and `.git/`) with
   `token_get_all()` and evaluates each require/include path expression with a
   small static evaluator: string literals, concatenation,
   `__DIR__`/`__FILE__`, `define()`'d constants, and `dirname()` calls.
3. Reports a `require_once` as redundant when its resolved target is in the
   autoloadable set.

## Development

```sh
git clone git@github.com:lll-lll-lll-lll/require-once-lint.git
cd require-once-lint
composer install
composer check   # php-cs-fixer + phpstan + phpunit
```

Use `composer cs-fix` to apply PHP-CS-Fixer changes.

## License

[MIT](LICENSE)
