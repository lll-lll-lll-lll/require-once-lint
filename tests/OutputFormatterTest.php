<?php

declare(strict_types=1);

namespace Depone\Tests;

use PHPUnit\Framework\TestCase;
use Depone\Internal\Core\OutputFormatter;

final class OutputFormatterTest extends TestCase
{
    public function testFormatSummaryOutputIsByteExact(): void
    {
        // The text output is part of the public CLI contract: redundant rows
        // print unindented without a detail, conflicting rows print indented
        // with one, and every section appears even when empty.
        $result = [
            'redundant' => [
                ['file' => 'public/index.php', 'line' => 5, 'target' => 'src/Bar.php'],
            ],
            'conflicting' => [
                [
                    'file' => 'public/b.php',
                    'line' => 9,
                    'target' => 'src/Dup.php',
                    'detail' => 'App\Dup is autoloaded from classmap/Dup.php — this require loads a shadowed copy',
                ],
            ],
            'unresolved' => [
                ['file' => 'public/c.php', 'line' => 3, 'type' => 'include', 'reason' => 'complex', 'expr' => "SITE_ROOT . '/x.php'"],
            ],
            'edges' => [],
        ];

        $expected = 'redundant_require_once=1' . PHP_EOL
            . 'public/index.php:5 => src/Bar.php' . PHP_EOL
            . PHP_EOL
            . 'conflicting_require_once=1' . PHP_EOL
            . '  public/b.php:9 => src/Dup.php  (App\Dup is autoloaded from classmap/Dup.php — this require loads a shadowed copy)' . PHP_EOL
            . PHP_EOL
            . 'unresolved_include_require=1' . PHP_EOL
            . "  public/c.php:3 [complex] SITE_ROOT . '/x.php'" . PHP_EOL;

        self::assertSame($expected, (new OutputFormatter())->formatSummary($result));
    }

    public function testFormatSummaryPrintsEverySectionWhenEmpty(): void
    {
        $result = [
            'redundant' => [],
            'conflicting' => [],
            'unresolved' => [],
            'edges' => [],
        ];

        $expected = 'redundant_require_once=0' . PHP_EOL
            . PHP_EOL
            . 'conflicting_require_once=0' . PHP_EOL
            . PHP_EOL
            . 'unresolved_include_require=0' . PHP_EOL;

        self::assertSame($expected, (new OutputFormatter())->formatSummary($result));
    }

    public function testFormatSummaryJsonCarriesEverySectionButNotEdges(): void
    {
        $result = [
            'redundant' => [
                ['file' => 'public/index.php', 'line' => 5, 'target' => 'src/Bar.php'],
            ],
            'conflicting' => [
                [
                    'file' => 'public/b.php',
                    'line' => 9,
                    'target' => 'src/Dup.php',
                    'detail' => 'App\Dup is autoloaded from classmap/Dup.php — this require loads a shadowed copy',
                ],
            ],
            'unresolved' => [
                ['file' => 'public/c.php', 'line' => 3, 'type' => 'include', 'reason' => 'complex', 'expr' => "SITE_ROOT . '/x.php'"],
            ],
            'edges' => [
                ['from' => 'public/index.php', 'line' => 5, 'type' => 'require_once', 'to' => 'src/Bar.php'],
            ],
        ];

        $json = (new OutputFormatter())->formatSummaryJson($result);

        // Output ends with a single trailing newline and is valid JSON.
        self::assertStringEndsWith('}' . PHP_EOL, $json);
        // Slashes stay unescaped so paths read naturally.
        self::assertStringContainsString('src/Bar.php', $json);
        self::assertStringNotContainsString('src\/Bar.php', $json);

        $decoded = json_decode($json, true);
        self::assertSame(
            [
                'redundant' => $result['redundant'],
                'conflicting' => $result['conflicting'],
                'unresolved' => $result['unresolved'],
            ],
            $decoded
        );
        // edges is informational and, like the text summary, excluded.
        self::assertArrayNotHasKey('edges', $decoded);
    }

    public function testFormatSummaryJsonEmptySectionsAreEmptyArrays(): void
    {
        $result = ['redundant' => [], 'conflicting' => [], 'unresolved' => [], 'edges' => []];

        $decoded = json_decode((new OutputFormatter())->formatSummaryJson($result), true);

        self::assertSame(['redundant' => [], 'conflicting' => [], 'unresolved' => []], $decoded);
    }

    public function testFormatInventoryOutputIsByteExact(): void
    {
        // The inventory text output is part of the public CLI contract: one
        // block per kept target — required_from count, reasons, unreachable
        // classes when present, then one kind:line + excerpt row per side
        // effect.
        $result = [
            'redundant' => [],
            'conflicting' => [],
            'unresolved' => [],
            'kept' => [
                [
                    'target' => 'src/WrongPath.php',
                    'requiredFrom' => [['file' => 'public/index.php', 'line' => 6]],
                    'reasons' => ['class_not_autoloadable'],
                    'sideEffects' => [],
                    'unreachableClasses' => ['App\Sub\Missing'],
                ],
                [
                    'target' => 'src/helpers.php',
                    'requiredFrom' => [
                        ['file' => 'public/index.php', 'line' => 8],
                        ['file' => 'public/other.php', 'line' => 3],
                    ],
                    'reasons' => ['no_types', 'side_effects'],
                    'sideEffects' => [
                        ['kind' => 'function', 'line' => 7, 'excerpt' => 'function h($s) { return $s; }'],
                        ['kind' => 'define', 'line' => 3, 'excerpt' => "define('APP_ROOT', __DIR__);"],
                    ],
                    'unreachableClasses' => [],
                ],
            ],
            'edges' => [],
        ];

        $expected = 'kept_require_once=2' . PHP_EOL
            . PHP_EOL
            . 'src/WrongPath.php' . PHP_EOL
            . '  required_from=1' . PHP_EOL
            . '  reasons=class_not_autoloadable' . PHP_EOL
            . '  unreachable_classes=App\Sub\Missing' . PHP_EOL
            . PHP_EOL
            . 'src/helpers.php' . PHP_EOL
            . '  required_from=2' . PHP_EOL
            . '  reasons=no_types,side_effects' . PHP_EOL
            . '  function:7  function h($s) { return $s; }' . PHP_EOL
            . "  define:3  define('APP_ROOT', __DIR__);" . PHP_EOL;

        self::assertSame($expected, (new OutputFormatter())->formatInventory($result));
    }

    public function testFormatInventoryPrintsHeaderWhenEmpty(): void
    {
        $result = ['redundant' => [], 'conflicting' => [], 'unresolved' => [], 'kept' => [], 'edges' => []];

        self::assertSame(
            'kept_require_once=0' . PHP_EOL,
            (new OutputFormatter())->formatInventory($result)
        );
    }

    public function testFormatInventoryJsonCarriesOnlyKept(): void
    {
        $result = [
            'redundant' => [
                ['file' => 'public/index.php', 'line' => 5, 'target' => 'src/Bar.php'],
            ],
            'conflicting' => [],
            'unresolved' => [],
            'kept' => [
                [
                    'target' => 'src/helpers.php',
                    'requiredFrom' => [['file' => 'public/index.php', 'line' => 8]],
                    'reasons' => ['no_types', 'side_effects'],
                    'sideEffects' => [
                        ['kind' => 'function', 'line' => 7, 'excerpt' => 'function h($s) { return $s; }'],
                    ],
                    'unreachableClasses' => [],
                ],
            ],
            'edges' => [],
        ];

        $decoded = json_decode((new OutputFormatter())->formatInventoryJson($result), true);

        // The inventory is its own report: findings sections stay out of it.
        self::assertSame(['kept' => $result['kept']], $decoded);
    }

    public function testFormatReverseTraceJsonMirrorsTraceResult(): void
    {
        $trace = [
            'target' => 'src/Bar.php',
            'directCallers' => ['public/index.php'],
            'entrypoints' => ['public/index.php'],
            'paths' => [['public/index.php', 'src/Bar.php']],
            'truncated' => false,
        ];

        $decoded = json_decode((new OutputFormatter())->formatReverseTraceJson($trace), true);

        self::assertSame($trace, $decoded);
    }

    public function testFormatSummaryJsonSubstitutesInvalidUtf8InsteadOfLosingTheReport(): void
    {
        // `expr` carries raw bytes from the analyzed source; legacy files are
        // not always UTF-8. json_encode() fails on such input unless invalid
        // sequences are substituted — and a failure would silently replace the
        // whole report with '{}'.
        $result = [
            'redundant' => [
                ['file' => 'public/index.php', 'line' => 5, 'target' => 'src/Bar.php'],
            ],
            'conflicting' => [],
            'unresolved' => [
                ['file' => 'legacy/a.php', 'line' => 3, 'type' => 'include', 'reason' => 'variable', 'expr' => "\$caf\xE9 . '/x.php'"],
            ],
            'edges' => [],
        ];

        $json = (new OutputFormatter())->formatSummaryJson($result);

        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        // The findings survive; the invalid byte is replaced with U+FFFD.
        self::assertSame($result['redundant'], $decoded['redundant']);
        self::assertSame("\$caf\u{FFFD} . '/x.php'", $decoded['unresolved'][0]['expr']);
    }
}
