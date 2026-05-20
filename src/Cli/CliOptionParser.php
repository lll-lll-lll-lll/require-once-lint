<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Cli;

use RedundantRequireOnce\Exception\CliOptionParseException;

/**
 * CLI option parser.
 */
final class CliOptionParser
{
    /**
     * Parses command-line arguments and returns a CliOptions instance.
     *
     * @param array $argv Command-line arguments
     * @throws CliOptionParseException If an unknown option is specified
     */
    public function parse(array $argv): CliOptions
    {
        $json               = false;
        $help               = false;
        $trace              = null;
        $deps               = null;
        $maxPaths           = 20;
        $maxDepth           = 25;
        $includeNonAutoload = false;
        $consts             = [];

        $argc = count($argv);
        for ($i = 1; $i < $argc; $i++) {
            $arg = $argv[$i];

            if ($arg === '--json') {
                $json = true;
                continue;
            }
            if ($arg === '--help' || $arg === '-h') {
                $help = true;
                continue;
            }
            if ($arg === '--include-non-autoload') {
                $includeNonAutoload = true;
                continue;
            }

            // --define NAME=VALUE (can be specified multiple times)
            if ($arg === '--define') {
                if (!isset($argv[$i + 1])) {
                    throw new CliOptionParseException('--define requires a value');
                }
                [$name, $value] = $this->parseDefineValue((string)$argv[$i + 1]);
                $consts[$name] = $value;
                $i++;
                continue;
            }
            if (str_starts_with($arg, '--define=')) {
                $raw = substr($arg, strlen('--define='));
                if ($raw === '') {
                    throw new CliOptionParseException('--define requires a non-empty value');
                }
                [$name, $value] = $this->parseDefineValue($raw);
                $consts[$name] = $value;
                continue;
            }

            $stringResult = $this->parseStringOption($arg, $argv, $i, ['--trace', '--deps']);
            if ($stringResult !== null) {
                [$key, $val, $i] = $stringResult;
                if ($key === 'trace') {
                    $trace = $val;
                } elseif ($key === 'deps') {
                    $deps = $val;
                }
                continue;
            }

            $intResult = $this->parseIntOption($arg, $argv, $i, ['--max-paths' => 'maxPaths', '--max-depth' => 'maxDepth']);
            if ($intResult !== null) {
                [$key, $val, $i] = $intResult;
                if ($key === 'maxPaths') {
                    $maxPaths = $val;
                } else {
                    $maxDepth = $val;
                }
                continue;
            }

            throw new CliOptionParseException("Unknown option: {$arg}");
        }

        return new CliOptions(
            json:               $json,
            help:               $help,
            trace:              $trace,
            deps:               $deps,
            maxPaths:           $maxPaths,
            maxDepth:           $maxDepth,
            includeNonAutoload: $includeNonAutoload,
            consts:             $consts,
        );
    }

    /**
     * Splits a "--define" value of the form "NAME=VALUE" into [name, value].
     *
     * @return array{0: string, 1: string}
     * @throws CliOptionParseException If the format is invalid
     */
    private function parseDefineValue(string $raw): array
    {
        $eqPos = strpos($raw, '=');
        if ($eqPos === false || $eqPos === 0) {
            throw new CliOptionParseException("--define requires NAME=VALUE format, got '{$raw}'");
        }
        return [substr($raw, 0, $eqPos), substr($raw, $eqPos + 1)];
    }

    /**
     * Parses an option that takes a string value.
     *
     * @return array{0: string, 1: string, 2: int}|null [option name, value, updated index]
     * @throws CliOptionParseException If the value is missing
     */
    private function parseStringOption(string $arg, array $argv, int $i, array $optionNames): ?array
    {
        foreach ($optionNames as $optionName) {
            $key = ltrim($optionName, '-');

            // --option value form
            if ($arg === $optionName) {
                if (!isset($argv[$i + 1])) {
                    throw new CliOptionParseException("{$optionName} requires a value");
                }
                $value = (string)$argv[$i + 1];
                if (str_starts_with($value, '-')) {
                    throw new CliOptionParseException("{$optionName} requires a value, got '{$value}'");
                }
                return [$key, $value, $i + 1];
            }

            // --option=value form
            $prefix = $optionName . '=';
            if (str_starts_with($arg, $prefix)) {
                $value = substr($arg, strlen($prefix));
                if ($value === '') {
                    throw new CliOptionParseException("{$optionName} requires a non-empty value");
                }
                return [$key, $value, $i];
            }
        }

        return null;
    }

    /**
     * Parses an option that takes an integer value.
     *
     * @param array<string, string> $optionMap Map of option name => result key name
     * @return array{0: string, 1: int, 2: int}|null [result key name, value, updated index]
     * @throws CliOptionParseException If the value is missing or invalid
     */
    private function parseIntOption(string $arg, array $argv, int $i, array $optionMap): ?array
    {
        foreach ($optionMap as $optionName => $resultKey) {
            // --option value form
            if ($arg === $optionName) {
                if (!isset($argv[$i + 1])) {
                    throw new CliOptionParseException("{$optionName} requires a value");
                }
                $rawValue = (string)$argv[$i + 1];
                if (str_starts_with($rawValue, '-') && !ctype_digit(substr($rawValue, 1))) {
                    throw new CliOptionParseException("{$optionName} requires a value, got '{$rawValue}'");
                }
                return [$resultKey, $this->parseNonNegativeInt($rawValue, $optionName), $i + 1];
            }

            // --option=value form
            $prefix = $optionName . '=';
            if (str_starts_with($arg, $prefix)) {
                $rawValue = substr($arg, strlen($prefix));
                if ($rawValue === '') {
                    throw new CliOptionParseException("{$optionName} requires a non-empty value");
                }
                return [$resultKey, $this->parseNonNegativeInt($rawValue, $optionName), $i];
            }
        }

        return null;
    }

    /**
     * Parses a string as a non-negative integer (allows zero).
     *
     * @throws CliOptionParseException If the value is invalid
     */
    private function parseNonNegativeInt(string $value, string $optionName): int
    {
        if (!ctype_digit($value)) {
            throw new CliOptionParseException("{$optionName} requires a non-negative integer, got '{$value}'");
        }

        return (int)$value;
    }

    /**
     * Returns the help message.
     */
    public static function getHelpMessage(): string
    {
        return <<<TXT
Usage:
  php-find-redundant-require-once [--json] [--trace <path>] [--deps <path>] [--include-non-autoload] [--define NAME=VALUE] [--max-paths <n>] [--max-depth <n>]

Options:
  --json            Output JSON
  --trace <path>    Show reverse caller traces for the given file path (repo relative)
                    (who requires this file?)
                    Includes both require_once and autoload (use/new/extends etc.) edges.
  --deps <path>     Show forward dependency traces for the given file path (repo relative)
                    (what files does this file require?)
                    Includes both require_once and autoload (use/new/extends etc.) edges.
  --include-non-autoload
                    Include require_once targets that are not registered in Composer autoload
  --define NAME=VALUE
                    Define a global constant used in require_once expressions.
                    Can be specified multiple times.
                    Example: --define BASE_DIR=/var/www/htdocs/
  --max-paths <n>   Maximum number of trace paths (default: 20, 0 = unlimited)
  --max-depth <n>   Maximum trace depth (default: 25, 0 = unlimited)
  --help            Show this help
TXT;
    }
}
