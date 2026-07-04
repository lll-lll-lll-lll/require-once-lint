# depone

[![CI](https://github.com/lll-lll-lll-lll/require-once-lint/actions/workflows/ci.yml/badge.svg)](https://github.com/lll-lll-lll-lll/require-once-lint/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/lll-lll-lll-lll/depone)](https://packagist.org/packages/lll-lll-lll-lll/depone)
[![PHP Version](https://img.shields.io/packagist/dependency-v/lll-lll-lll-lll/depone/php)](https://packagist.org/packages/lll-lll-lll-lll/depone)
[![License](https://img.shields.io/packagist/l/lll-lll-lll-lll/depone?cacheSeconds=3600)](LICENSE)

> *Rerum curam depone.* — Lay down your worries.

A static analysis tool for PHP that helps you eliminate require_once-related
issues.

![depone finding redundant require_once statements](docs/demo.gif)

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

## Example

A typical legacy front controller, after Composer autoload has been introduced:

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Greeting.php';
require_once __DIR__ . '/../src/Legacy/Mailer.php';
require_once __DIR__ . '/../src/helpers.php';
require_once $config['plugins_dir'] . '/bootstrap.php';

$greeting = new App\Greeting();
echo $greeting->say();
```

Running `depone` from the project root:

```
redundant_require_once=3
public/index.php:4 => src/Greeting.php
public/index.php:5 => src/Legacy/Mailer.php
public/index.php:6 => src/helpers.php

unresolved_include_require=1
  public/index.php:7 [variable] $config['plugins_dir'] . '/bootstrap.php'
```

Lines 4–6 are already covered by Composer's autoloader, so they can be
deleted. Line 7 builds its path from a variable, so `depone` cannot resolve it
statically — it is reported as unresolved rather than silently ignored, and
is excluded from the redundant list. The `require_once` for
`vendor/autoload.php` on line 3 is never reported: it isn't part of the
autoloadable set itself, and its target is resolvable but not redundant.

Before deleting the `require_once` for `Mailer.php`, confirm who else depends
on it:

```sh
depone --trace src/Legacy/Mailer.php
```

```
trace_target=src/Legacy/Mailer.php
direct_callers=1
  - public/index.php
entrypoint_candidates=1
  - public/index.php
trace_paths=1
  1. public/index.php -[r]-> src/Legacy/Mailer.php
```

With a single caller and a clear trace path, the three lines can be removed
safely and left to Composer's autoloader.

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

## Relationship to PHPStan and Rector

depone isn't a replacement for PHPStan, Psalm, or Rector — use it alongside
them. It covers one narrow thing they don't have a rule for: deciding whether
a `require_once` is made redundant by Composer autoload. That decision is a
path-resolution + autoload-matching problem rather than an AST transformation,
which is why it lives as a small standalone tool. It is also report-only by
design and never rewrites your code.

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
