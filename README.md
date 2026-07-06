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
configuration, and without ever rewriting your code:

- **redundant** — the target is already autoloaded; deleting the require is
  provably safe
- **fixable** — the target *should* be autoloaded but isn't (broken autoload
  config)
- **conflicting** — the require loads a shadowed copy; deleting it would
  change which class definition loads
- **needed** — the require is legitimate and load-bearing (helper functions,
  side effects, or nothing autoload covers)

Every `require_once` in the repository ends up in exactly one of those four
buckets, or under `unresolved` with the specific reason it couldn't be
resolved statically — nothing is ever silently skipped.

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

fixable_require_once=0

conflicting_require_once=0

unresolved_include_require=1
  public/index.php:7 [variable] $config['plugins_dir'] . '/bootstrap.php'
```

Lines 4–5 declare classes that Composer already autoloads back to those same
files, so they can be deleted. Line 6 (`src/helpers.php`) defines helper
functions rather than a class, so autoload never covers it and the require is
load-bearing — it is accounted for as `needed`, kept out of this default text
output but visible with `--format=json` and counted by `--explain` (see
[Flags](#flags) below). Line 7 builds its path from a variable, so `depone`
cannot resolve it statically — it is reported as unresolved rather than
silently ignored, and is excluded from the redundant list. The `require_once`
for `vendor/autoload.php` on line 3 is `needed` for the same reason: it
declares no classes at all, so autoload cannot cover it either, and the
require stays load-bearing.

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

Each `require_once` whose target resolves statically falls into one of four
categories:

| Section | Meaning | What to do |
| --- | --- | --- |
| `redundant_require_once` | The target is already autoloaded, and deleting the require provably changes nothing. | Delete the require. |
| `fixable_require_once` | The target declares a class that matches a PSR-4/PSR-0 rule, but the rule derives a path that does not exist — so it never autoloads. | Fix the autoload config, then delete the require. |
| `conflicting_require_once` | The target declares a class that Composer autoloads from a *different* file. Deleting the require would change which definition loads. | Investigate the shadowed copy before touching the require. |
| `needed` *(absent from this default text output — see `--format=json` / `--explain`)* | The target is legitimately not autoloadable: no matching rule, it declares no types, or it also defines functions/constants or runs top-level code. | Leave it; the require is load-bearing. |

A require is only ever called `redundant` when deleting it is provably safe:
the target is an eager `autoload.files` entry, or it declares nothing but
class-like types (no functions, constants, or side effects) and *every* class
it declares autoloads back to that same file. `fixable` and `conflicting` are
flagged but never presented as free deletions.

Include/require statements whose path expression cannot be resolved statically
are never silently skipped — they are listed under
`unresolved_include_require` with a reason:

| Reason | Meaning |
| --- | --- |
| `variable` | the expression contains a variable |
| `method_call` | the expression contains an object method call |
| `static_access` | the expression contains `::` access |
| `complex` | anything else the evaluator cannot resolve |

## Flags

- **`--explain`** — human-readable evidence: prepends a coverage header and
  prints the autoload proof under each redundant finding. This output is not
  a frozen format and may change shape between releases; the plain output
  above is.

  ```sh
  vendor/bin/depone --explain
  ```

  ```
  includes_total=5
  resolved=5
  unresolved=0
  needed_require_once=1

  redundant_require_once=1
  public/index.php:5 => src/Reachable.php
      App\Reachable => autoloaded via psr-4 from src/Reachable.php
      pure declaration file: autoload reproduces everything this file provides

  fixable_require_once=1
    public/index.php:6 => src/WrongPath.php  (App\Sub\Missing would load from src/Sub/Missing.php — fix autoload, then remove this require)

  conflicting_require_once=1
    public/index.php:7 => src/Dup.php  (App\Dup is autoloaded from classmap/Dup.php — this require loads a shadowed copy)

  unresolved_include_require=0
  ```

- **`--format=json`** — the machine-readable contract: the full evidence
  behind every finding, plus the `needed` section this default text output
  hides. `schema_version` is bumped whenever the shape changes in a
  backward-incompatible way.

  ```sh
  vendor/bin/depone --format=json
  ```

  ```json
  {
      "schema_version": 1,
      "summary": {
          "includes_total": 5,
          "resolved": 5,
          "unresolved": 0,
          "require_once": {"redundant": 1, "fixable": 1, "conflicting": 1, "needed": 1}
      },
      "redundant": [
          {
              "file": "public/index.php",
              "line": 5,
              "target": "src/Reachable.php",
              "proof": {
                  "eager": false,
                  "pure_declaration": true,
                  "classes": [
                      {"class": "App\\Reachable", "via": "psr-4", "prefix": "App\\", "path": "src/Reachable.php"}
                  ]
              }
          }
      ],
      "fixable": [
          {"file": "public/index.php", "line": 6, "target": "src/WrongPath.php", "class": "App\\Sub\\Missing", "expected_path": "src/Sub/Missing.php", "detail": "..."}
      ],
      "conflicting": [
          {"file": "public/index.php", "line": 7, "target": "src/Dup.php", "class": "App\\Dup", "loaded_from": "classmap/Dup.php", "detail": "..."}
      ],
      "needed": [
          {"file": "public/index.php", "line": 8, "target": "src/helper.php", "reason": "target declares no types"}
      ],
      "unresolved": []
  }
  ```

- **`--verify`** — cross-checks every redundant finding against the autoload
  maps Composer actually dumped under `vendor/composer/`, using Composer's
  own `ClassLoader`; it never executes your project's code (in particular, it
  never touches `vendor/autoload.php`). A mismatch means either a stale
  `composer dump-autoload` or a bug in depone's own resolution — both worth
  knowing about before you delete anything.

  ```sh
  vendor/bin/depone --verify
  ```

  ```
  ...
  verify_mismatches=1
    public/legacy.php:12 => src/Old.php  (App\Old: composer loader resolves src/New.php)
  ```

  With `--format=json`, each redundant entry gains a `"verified"` boolean and
  the document gains a top-level `"verify"` block with `checked`/`verified`
  counts and the `mismatches` array.

- **`--trace <file>`** — shown in [Quick start](#quick-start) above: prints
  who else requires a given file, so you can confirm nobody depends on it
  before deleting its require.

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | The analysis ran and found no redundant, fixable, or conflicting require (and, with `--verify`, no mismatch). |
| `1` | The analysis ran and reported at least one redundant, fixable, or conflicting require (or, with `--verify`, at least one mismatch). |
| `2` | The analysis could not run (unreadable `composer.json`, unknown option, missing autoload maps for `--verify`, ...). |

`unresolved_include_require` entries are reported but never affect the exit
code: legacy dynamic includes are often legitimate, and failing on them would
turn the first run red on almost every legacy project. `--trace` output is
informational and always exits `0` unless the analysis itself fails.

## Using in CI

Because findings exit non-zero, a plain step fails the build as soon as a
redundant, fixable, or conflicting require appears (or, with `--verify`, a
verify mismatch):

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

1. Reads the autoload rules from `composer.json` (`psr-4`, `psr-0`, `classmap`,
   and `files`, including their `autoload-dev` counterparts).
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
   - **fixable** — a declared class matches a PSR rule, but the rule derives a
     path that does not exist, so it never autoloads.
   - **conflicting** — a declared class autoloads from a *different* file, so
     the require loads a shadowed copy.
   - otherwise the require is `needed` (no matching rule, no declared types,
     or the file also defines functions/constants or runs top-level code —
     autoload would not reproduce those, so the require is load-bearing).
     Absent from the default text output; visible with `--format=json` and
     counted by `--explain`.
4. With `--verify`, cross-checks every redundant finding against the autoload
   maps Composer actually dumped under `vendor/composer/` (see [Flags](#flags)).

## Relationship to PHPStan and Rector

depone isn't a replacement for PHPStan, Psalm, or Rector — use it alongside
them. It covers one narrow thing they don't have a rule for: relating each
`require_once` to Composer autoload — is the target already autoloaded, should
it be but isn't, or does it shadow an autoloaded copy? That is a
path-resolution + autoload-matching problem rather than an AST transformation,
which is why it lives as a small standalone tool. `composer dump-autoload
--strict-psr`/`--strict-ambiguous` validates the autoload config on its own,
but never parses source-level `require_once` statements, so it cannot make this
connection. depone is also report-only by design and never rewrites your code.

## Scope

`depone` is a CLI tool. Its supported public interface is the command name,
options, exit codes, and command output (the default text output and the
`--format=json` document; `--explain` is explicitly not frozen). PHP classes
under `src/` are internal implementation details and may change without
backward compatibility guarantees.

depone is also feature-complete by design: a small, finished tool that
answers one question well — which `require_once` statements can go, and with
what proof. What it deliberately will not grow:

- **No `--fix` or rewriting.** Deleting code is a job for rewriting tools such
  as Rector; depone is the audit that tells you which deletions are safe and
  what they would change, not the tool that performs them.
- **No baseline or suppression machinery.** Every finding is either handled or
  it isn't; there is no mechanism to mark a finding as ignored.
- **No plugin system.** The tool covers Composer autoload and require/include
  statements — nothing more.

What is always welcome: bug reports, and real-world require/include patterns
depone fails to resolve or misclassifies. `unresolved`-with-a-reason is a
promise, and any pattern that breaks it (silently missing from every section,
or reported under the wrong category) is a bug.

Versioning is 0.x deliberately, while the tool and its output settle. The
JSON schema carries its own `schema_version`; 1.0 is reserved for once that
schema has downstream consumers depending on it.

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
