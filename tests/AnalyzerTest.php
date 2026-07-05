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

    public function testRedundantRequiresOnlyProvablySafeTargets(): void
    {
        // A require is redundant only when deleting it changes nothing: the
        // target is an autoload.files entry, or every class it declares
        // round-trips AND it declares no functions/constants/side effects.
        $projectRoot = $this->getFixturePath('RedundantSafetyProject');

        $result = (new Analyzer($projectRoot))->run();

        self::assertSame(
            [
                // Pure declaration file whose class round-trips.
                [
                    'file' => 'public/index.php',
                    'line' => 5,
                    'target' => 'src/Pure.php',
                ],
                // autoload.files entry, loaded eagerly.
                [
                    'file' => 'public/index.php',
                    'line' => 8,
                    'target' => 'src/eager.php',
                ],
            ],
            $result['redundant']
        );

        // src/WithShadow.php (App\Shadowed autoloads from classmap/Shadowed.php)
        // and src/WithFunction.php (also declares a function) are load-bearing:
        // deleting their requires would change behavior, so neither is redundant.
        $redundantTargets = array_column($result['redundant'], 'target');
        self::assertNotContains('src/WithShadow.php', $redundantTargets);
        self::assertNotContains('src/WithFunction.php', $redundantTargets);

        self::assertSame([], $result['unresolved']);
    }

    public function testClassifiesRequiresByAutoloadRelationship(): void
    {
        $projectRoot = $this->getFixturePath('RequireClassificationProject');

        $result = (new Analyzer($projectRoot))->run();

        // Redundant: App\Reachable round-trips to the required file.
        self::assertSame(
            [
                [
                    'file' => 'public/index.php',
                    'line' => 5,
                    'target' => 'src/Reachable.php',
                ],
            ],
            $result['redundant']
        );

        // Fixable: App\Sub\Missing matches the App\ rule but derives a missing path.
        self::assertSame(
            [
                [
                    'file' => 'public/index.php',
                    'line' => 6,
                    'target' => 'src/WrongPath.php',
                    'detail' => 'App\Sub\Missing would load from src/Sub/Missing.php'
                        . ' — fix autoload, then remove this require',
                ],
            ],
            $result['fixable']
        );

        // Conflicting: App\Dup autoloads from classmap/Dup.php, not the required file.
        self::assertSame(
            [
                [
                    'file' => 'public/index.php',
                    'line' => 7,
                    'target' => 'src/Dup.php',
                    'detail' => 'App\Dup is autoloaded from classmap/Dup.php'
                        . ' — this require loads a shadowed copy',
                ],
            ],
            $result['conflicting']
        );

        // src/helper.php declares no type: a "needed" require, reported nowhere.
        self::assertSame([], $result['unresolved']);
    }

    public function testMultiClassTargetIsNeverCalledRedundantUnlessEveryClassRoundTrips(): void
    {
        // Regression: one round-tripping class must not mark the whole require
        // redundant. Every src file in this fixture declares a healthy class
        // PLUS a problematic one; the problematic class decides the category.
        // public/second.php re-requires targets already classified via
        // public/index.php, so the memoized results are exercised too.
        $projectRoot = $this->getFixturePath('RequireClassificationEdgeProject');

        $result = (new Analyzer($projectRoot))->run();

        // Only the eager `files` entries are redundant (loaded on Composer
        // init, so the require is a no-op regardless of declarations) —
        // including the autoload-dev one.
        self::assertSame(
            [
                [
                    'file' => 'public/index.php',
                    'line' => 8,
                    'target' => 'src/eager.php',
                ],
                [
                    'file' => 'public/second.php',
                    'line' => 8,
                    'target' => 'dev/dev-eager.php',
                ],
            ],
            $result['redundant']
        );

        // App\MixedMissing round-trips, but App\Sub\Gone derives a missing
        // path. The same target required from a second file yields a second
        // entry with the identical detail (served from the per-target memo).
        self::assertSame(
            [
                [
                    'file' => 'public/index.php',
                    'line' => 6,
                    'target' => 'src/MixedMissing.php',
                    'detail' => 'App\Sub\Gone would load from src/Sub/Gone.php'
                        . ' — fix autoload, then remove this require',
                ],
                [
                    'file' => 'public/second.php',
                    'line' => 5,
                    'target' => 'src/MixedMissing.php',
                    'detail' => 'App\Sub\Gone would load from src/Sub/Gone.php'
                        . ' — fix autoload, then remove this require',
                ],
            ],
            $result['fixable']
        );

        // MixedShadow: App\Winner is autoloaded elsewhere. MixedBoth declares
        // the fixable App\Sub\AlsoGone FIRST and the shadowed App\Winner2
        // second: the conflict must override the pending fixable candidate.
        self::assertSame(
            [
                [
                    'file' => 'public/index.php',
                    'line' => 5,
                    'target' => 'src/MixedShadow.php',
                    'detail' => 'App\Winner is autoloaded from classmap/Winner.php'
                        . ' — this require loads a shadowed copy',
                ],
                [
                    'file' => 'public/index.php',
                    'line' => 9,
                    'target' => 'src/MixedBoth.php',
                    'detail' => 'App\Winner2 is autoloaded from classmap/Winner2.php'
                        . ' — this require loads a shadowed copy',
                ],
            ],
            $result['conflicting']
        );

        // Silent by design: MixedGlobal (declares an uncovered class, memoized
        // null on the second require), the nonexistent DoesNotExist.php target
        // (no declarations, must not warn or crash), and the two side-effect
        // files below.
        self::assertSame([], $result['unresolved']);

        // WithFunction and WithSideEffect each round-trip their class, but the
        // files also declare a function / a constant + top-level statement.
        // Autoload would not reproduce those, so the requires are load-bearing
        // and must appear in no section.
        $allTargets = array_merge(
            array_column($result['redundant'], 'target'),
            array_column($result['fixable'], 'target'),
            array_column($result['conflicting'], 'target')
        );
        self::assertNotContains('src/WithFunction.php', $allTargets);
        self::assertNotContains('src/WithSideEffect.php', $allTargets);
    }

    private function getFixturePath(string $name): string
    {
        $path = realpath(__DIR__ . '/Fixture/' . $name);
        self::assertNotFalse($path);

        return $path;
    }
}
