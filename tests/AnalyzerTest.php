<?php

declare(strict_types=1);

namespace Depone\Tests;

use PHPUnit\Framework\TestCase;
use Depone\Internal\Core\Analyzer;
use Depone\Internal\Core\DependencyGraph;

final class AnalyzerTest extends TestCase
{
    public function testRunDetectsRedundantRequireStatement(): void
    {
        $projectRoot = $this->getFixturePath('SampleProject');

        $result = (new Analyzer($projectRoot))->run();

        self::assertSame(
            [
                [
                    'file' => 'public/grouped.php',
                    'line' => 5,
                    'target' => 'src/Foo.php',
                ],
                [
                    'file' => 'public/index.php',
                    'line' => 5,
                    'target' => 'src/Bar.php',
                ],
            ],
            $result['redundant']
        );
        self::assertSame([], $result['unresolved']);
    }

    public function testReverseTraceFollowsRequireOnceEdgesOnly(): void
    {
        $projectRoot = $this->getFixturePath('SampleProject');

        $result = (new Analyzer($projectRoot))->run();

        $graph = new DependencyGraph($result['edges'], $projectRoot);
        $trace = $graph->buildReverseTrace('src/Bar.php', 20, 25);

        self::assertSame('src/Bar.php', $trace['target']);
        self::assertSame(['public/index.php'], $trace['directCallers']);
        self::assertSame(['public/index.php'], $trace['entrypoints']);
        self::assertSame(
            [
                ['public/index.php', 'src/Bar.php'],
            ],
            $trace['paths']
        );
        self::assertFalse($trace['truncated']);
    }

    public function testRunResolvesGroupedParenthesesFollowedByConcatenation(): void
    {
        $projectRoot = $this->getFixturePath('SampleProject');

        $result = (new Analyzer($projectRoot))->run();

        // Regression: `(dirname(__FILE__)) . '/../src/Foo.php'` must resolve in full.
        // readIncludeExprTokens() used to stop at the closing `)` of the leading
        // parenthesized group and silently discard the trailing concatenation.
        self::assertContains(
            [
                'from' => 'public/grouped.php',
                'line' => 5,
                'type' => 'require_once',
                'to' => 'src/Foo.php',
            ],
            $result['edges']
        );
        self::assertSame([], $result['unresolved']);
    }

    public function testRunDoesNotTreatHelperPhpUnderPsr4DirectoryAsAutoloaded(): void
    {
        $projectRoot = $this->getFixturePath('AnalyzerAutoloadCoverageProject');

        $result = (new Analyzer($projectRoot))->run();

        // Only tests/bootstrap.php => dev-tests/TestSupport.php is redundant.
        // public/index.php => src/helpers.php must NOT be flagged: a plain .php file
        // sitting under a PSR-4 directory is not actually autoloaded.
        self::assertSame(
            [
                [
                    'file' => 'tests/bootstrap.php',
                    'line' => 5,
                    'target' => 'dev-tests/TestSupport.php',
                ],
            ],
            $result['redundant']
        );
        self::assertSame([], $result['unresolved']);
    }

    private function getFixturePath(string $name): string
    {
        $path = realpath(__DIR__ . '/Fixture/' . $name);
        self::assertNotFalse($path);

        return $path;
    }
}
