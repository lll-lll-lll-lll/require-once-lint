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

    public function testFormatFixReportListsRemovedAndSkipped(): void
    {
        $report = [
            'removed' => [
                ['file' => 'public/index.php', 'line' => 5, 'target' => 'src/Foo.php'],
            ],
            'skipped' => [
                ['file' => 'public/x.php', 'line' => 3, 'target' => 'src/Y.php', 'reason' => 'shares a line with other code or a comment'],
            ],
        ];

        $expected = 'fixed_require_once=1' . PHP_EOL
            . 'public/index.php:5 => src/Foo.php' . PHP_EOL
            . PHP_EOL
            . 'skipped_require_once=1' . PHP_EOL
            . '  public/x.php:3 => src/Y.php  (shares a line with other code or a comment)' . PHP_EOL;

        self::assertSame($expected, (new OutputFormatter())->formatFixReport($report));
    }
}
