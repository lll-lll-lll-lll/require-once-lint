# Deleting depone's findings automatically with Rector

depone is deliberately report-only: it proves *which* `require_once`
statements are safe to delete, and never rewrites code. This example shows
the intended way to act on that report — feed it to
[Rector](https://github.com/rectorphp/rector), the PHP community's standard
refactoring tool, through a small custom rule.

The division of labor:

| | depone | Rector |
| --- | --- | --- |
| sees | the whole project (Composer autoload maps, class round-trips) | one file's AST at a time |
| decides | which requires are `redundant` (provably safe to delete) | nothing — executes the report |
| changes | nothing | deletes the statements, format-preserving, with `--dry-run` |

The interface between them is depone's JSON report: each `redundant` entry
carries `{file, line, target}`, and
[`RemoveDeponeRedundantRequireRector`](rector-rules/Rector/RemoveDeponeRedundantRequireRector.php)
joins on file + line, verifies the node at that position really is a
`require_once`, and removes it. Only `redundant` entries are consumed:
`conflicting` means "a human must investigate", and `unresolved` means
"depone could not prove anything" — neither is machine-actionable.

## Run it

```sh
cd example
composer install

php public/index.php        # the legacy app works, requires and all
composer preview            # depone report + rector --dry-run: shows 3 deletions
composer fix                # delete them, then re-run depone to confirm exit 0
php public/index.php        # still works — that is depone's guarantee
git diff .                  # exactly three require_once lines removed
```

`composer fix` runs the whole pipeline:

1. `vendor/bin/depone --format json > depone-report.json` — exit `1` just
   means "there are findings", so the script treats only exit `2` (analysis
   failed) as an error.
2. `vendor/bin/rector process` — deletes the reported statements.
3. `vendor/bin/depone` — exits `0`: nothing redundant is left.

What gets deleted and why:

- `public/index.php`: the requires of `src/constants.php` (an eager
  `autoload.files` entry — Composer loads it before any code runs) and
  `src/Greeting.php` (its class autoloads back to that exact file).
- `legacy/report.php`: the same `src/Greeting.php` require.

What stays, and why:

- `require_once __DIR__ . '/../vendor/autoload.php'` — load-bearing
  (bootstraps Composer; depone never reports vendor targets).
- `require_once __DIR__ . '/../src/helpers.php'` — load-bearing: it defines
  a function, which autoload cannot reproduce, so depone leaves it
  unreported and the rule never sees it.

To reset the example after running it: `git checkout -- example/`.

## Safety properties worth copying

- **Only `redundant` is consumed.** depone's contract is that deleting these
  is provably behavior-preserving; every other category needs a human.
- **A stale report cannot delete the wrong code.** The rule re-checks that
  the node at the reported file + line is a `require_once` statement; if the
  file changed since the report, the worst case is a skipped deletion.
  Still, generate the report and run Rector back-to-back (as `composer fix`
  does) so line numbers stay fresh.
- **One report entry buys one deletion.** If a line ever carried two
  `require_once` statements with a single report entry, the rule deletes at
  most one instead of both.

## Using this in your own project

Copy `rector-rules/` and `rector.php`, adjust `withPaths()` and the two
options (`REPORT_PATH`, `REPO_ROOT`), and add the `report`/`fix` scripts to
your `composer.json`. This example installs the released depone from
Packagist (`composer require --dev depone/depone rector/rector`); nothing
here depends on internals of either tool — only depone's documented JSON
output and Rector's documented custom-rule API.
