# depone

[![CI](https://github.com/lll-lll-lll-lll/depone/actions/workflows/ci.yml/badge.svg)](https://github.com/lll-lll-lll-lll/depone/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/depone/depone)](https://packagist.org/packages/depone/depone)
[![PHP Version](https://img.shields.io/packagist/dependency-v/depone/depone/php)](https://packagist.org/packages/depone/depone)
[![License](https://img.shields.io/packagist/l/depone/depone?cacheSeconds=3600)](LICENSE)

> Delete legacy `require_once` with proof, not guesswork.

Legacy PHP codebases carry hundreds of `require_once` lines that Composer
autoload made unnecessary years ago. Deleting them by hand is a gamble: some
are dead weight, some are hiding a broken autoload rule, and some load a
*different* copy of a class than the autoloader would.

`depone` reads every PHP file in your repository, statically resolves each
`require_once` target, and tells those cases apart — in one command, with no
configuration. It reports by default and rewrites nothing unless you opt in with
`--fix`, which deletes only the lines it can prove are safe:

- **redundant** — the target is already autoloaded; deleting the require is
  provably safe
- **conflicting** — the require loads a shadowed copy; deleting it would
  change which class definition loads

![depone finding redundant require_once statements](docs/demo.gif)

## Installation

Requires PHP 8.1+ and Composer.

```sh
composer require --dev depone/depone
```

## Quick start

Run the analyzer from the root of a Composer project:

```sh
vendor/bin/depone
```

Take a typical legacy front controller, written before Composer autoload was
introduced:

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
redundant_require_once=2
public/index.php:4 => src/Greeting.php
public/index.php:5 => src/Legacy/Mailer.php

conflicting_require_once=0

unresolved_include_require=1
  public/index.php:7 [variable] $config['plugins_dir'] . '/bootstrap.php'
```

Lines 4–5 declare classes that Composer already autoloads back to those same
files, so they can be deleted. Line 6 (`src/helpers.php`) is *not* reported:
it defines helper functions rather than a class, so autoload never covers it
and the require is load-bearing. Line 7 builds its path from a variable, so
`depone` cannot resolve it statically — it is reported as unresolved rather
than silently ignored, and is excluded from the redundant list. The
`require_once` for `vendor/autoload.php` on line 3 is never reported: it isn't
part of the autoloadable set itself, and its target is resolvable but not
redundant.

Before deleting the `require_once` for `Mailer.php`, confirm who else depends
on it:

```sh
vendor/bin/depone --trace src/Legacy/Mailer.php
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

With a single caller and a clear trace path, the two redundant lines can be
removed safely and left to Composer's autoloader.

## Understanding the report

Each `require_once` whose target resolves statically falls into one of three
categories:

| Section | Meaning | What to do |
| --- | --- | --- |
| `redundant_require_once` | The target is already autoloaded, and deleting the require provably changes nothing. | Delete the require. |
| `conflicting_require_once` | The target declares a class that Composer autoloads from a *different* file. Deleting the require would change which definition loads. | Investigate the shadowed copy before touching the require. |
| *(none — silent)* | The target is legitimately not autoloadable: no matching rule, a class whose derived path is missing, it declares no types, or it also defines functions/constants or runs top-level code. | Leave it; the require is load-bearing. |

A require is only ever called `redundant` when deleting it is provably safe:
the target is an eager `autoload.files` entry, or it declares nothing but
class-like types (no functions, constants, or side effects) and *every* class
it declares autoloads back to that same file. `conflicting` is flagged but
never presented as a free deletion.

Include/require statements whose path expression cannot be resolved statically
are never silently skipped — they are listed under
`unresolved_include_require` with a reason:

| Reason | Meaning |
| --- | --- |
| `variable` | the expression contains a variable |
| `method_call` | the expression contains an object method call |
| `static_access` | the expression contains `::` access |
| `complex` | anything else the evaluator cannot resolve |

## JSON output

Pass `--format json` to emit the report as JSON instead of the text summary —
useful for CI dashboards, editors, or piping into `jq`. The exit codes are
unchanged; only the output format differs (`text` is the default).

```sh
vendor/bin/depone --format json
```

```json
{
    "redundant": [
        { "file": "public/index.php", "line": 4, "target": "src/Greeting.php" },
        { "file": "public/index.php", "line": 5, "target": "src/Legacy/Mailer.php" }
    ],
    "conflicting": [],
    "unresolved": [
        { "file": "public/index.php", "line": 7, "type": "require_once", "reason": "variable", "expr": "$config['plugins_dir'] . '/bootstrap.php'" }
    ]
}
```

Redundant rows carry `file`, `line`, and `target`; conflicting rows add a
`detail` string. `--format json` also applies to `--trace`, emitting the trace
as a JSON object (`target`, `directCallers`, `entrypoints`, `paths`,
`truncated`).

## Deleting redundant requires (`--fix`)

`--fix` deletes the provably-`redundant` `require_once` statements in place. It
only ever removes what the report calls `redundant` — `conflicting` and
`unresolved` requires are hazards or unknowns and are never touched.

```sh
vendor/bin/depone --fix
```

```
fixed_require_once=2
public/index.php:4 => src/Greeting.php
public/index.php:5 => src/Legacy/Mailer.php

skipped_require_once=0
```

Removal is deliberately conservative: a statement is deleted only when it can be
located unambiguously and it sits on its own line(s) — nothing but whitespace
before the `require_once` and after the terminating `;`. A require that shares a
line with other code or carries a trailing comment is left in place and listed
under `skipped_require_once` for you to handle by hand. After editing, each file
is re-parsed and only written back if it still parses. Run `--fix` on a clean
working tree so the deletions are easy to review and revert.

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | The analysis ran and found no redundant or conflicting require. |
| `1` | The analysis ran and reported at least one redundant or conflicting require. |
| `2` | The analysis could not run (unreadable `composer.json`, unknown option, ...). |

`unresolved_include_require` entries are reported but never affect the exit
code: legacy dynamic includes are often legitimate, and failing on them would
turn the first run red on almost every legacy project. `--trace` and `--fix`
always exit `0` unless the analysis itself fails.

## Using in CI

Because findings exit non-zero, a plain step fails the build as soon as a
redundant or conflicting require appears:

```yaml
- run: composer install --no-progress
- run: vendor/bin/depone
```

To print the report without failing the build on findings, use:

```sh
vendor/bin/depone || [ $? -ne 2 ]
```

This ignores findings (exit `1`) but still fails the step when the analysis
could not run at all (exit `2`). A plain `|| true` would mask execution errors
too — the step would stay green even when no analysis happened.

## How it works

1. Loads the autoload rules. When Composer has dumped its autoloader
   (`vendor/composer/autoload_*.php` present), depone uses those generated maps:
   they merge the root project with every installed dependency exactly as
   Composer resolves them at runtime, so a class provided by a dependency is
   recognized too. Without a dumped autoloader it falls back to reading the root
   `composer.json` directly (`psr-4`, `psr-0`, `classmap`, and `files`,
   including their `autoload-dev` counterparts).
2. Finds every require/include (excluding `vendor/` and `.git/`) by tokenizing
   each file with `token_get_all()`, and evaluates the path expression with a
   small static evaluator: string literals, concatenation, `__DIR__`/`__FILE__`,
   `define()`'d constants, and `dirname()` calls. Expressions it cannot resolve
   are reported as `unresolved_include_require` rather than dropped.
3. For each resolved `require_once` target, parses the file with
   [nikic/php-parser](https://github.com/nikic/PHP-Parser) to find the
   class-like types it declares, and checks each declared class against the
   autoload rules from step 1:
   - **redundant** — the target is an eager `autoload.files` entry, or it
     declares nothing but types and *every* declared class resolves back to
     that same file. Deleting it is provably safe.
   - **conflicting** — a declared class autoloads from a *different* file, so
     the require loads a shadowed copy.
   - otherwise the require is left unreported (no matching rule, a declared
     class whose derived path is missing, no declared types, or the file also
     defines functions/constants or runs top-level code — autoload would not
     reproduce those, so the require is load-bearing).

## Relationship to PHPStan and Rector

depone isn't a replacement for PHPStan, Psalm, or Rector — use it alongside
them. It covers one narrow thing they don't have a rule for: relating each
`require_once` to Composer autoload — is the target already autoloaded, or does
it shadow an autoloaded copy? That is a path-resolution + autoload-matching
problem rather than an AST transformation, which is why it lives as a small
standalone tool. `composer dump-autoload
--strict-psr`/`--strict-ambiguous` validates the autoload config on its own,
but never parses source-level `require_once` statements, so it cannot make this
connection. depone reports by default; `--fix` opts in to deleting only the
provably-redundant requires, never the conflicting or unresolved ones.

## Scope and stability

`depone` is a CLI tool. Its supported public interface is the command name,
options, exit codes, and command output. PHP classes under `src/` are internal
implementation details and may change without backward compatibility guarantees.

## Development

```sh
git clone git@github.com:lll-lll-lll-lll/depone.git
cd depone
composer install
composer check   # php-cs-fixer + phpstan + phpunit
```

Use `composer cs-fix` to apply PHP-CS-Fixer changes.

## Why "depone"?

From the Latin *Rerum curam depone* — "lay down your worries." That is what it
does to a legacy codebase's `require_once` lines: put them down, one by one,
with proof.

## License

[MIT](LICENSE)
