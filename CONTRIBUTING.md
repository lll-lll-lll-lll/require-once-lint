# Contributing

## Development setup

```sh
git clone git@github.com:lll-lll-lll-lll/require-once-lint.git
cd require-once-lint
composer install
composer check
```

## Before submitting a PR

- Make sure `composer check` (coding style, static analysis, tests) is green.
- If your change affects the user-visible CLI (command name, options, exit
  codes, or output), add a one-line entry under **Unreleased** in
  [CHANGELOG.md](CHANGELOG.md).

## Scope

The public interface of this project is the `depone` CLI: the command name,
options, exit codes, and output. Classes under `src/` are `@internal` and
carry no backward compatibility guarantee — they may change or be removed at
any time without notice.
