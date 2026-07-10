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
}
