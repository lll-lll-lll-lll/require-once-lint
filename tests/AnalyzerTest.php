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
                    'proof' => [
                        'eager' => false,
                        'pure_declaration' => true,
                        'classes' => [
                            ['class' => 'App\Foo', 'via' => 'psr-4', 'prefix' => 'App\\', 'path' => 'src/Foo.php'],
                        ],
                    ],
                ],
                [
                    'file' => 'public/index.php',
                    'line' => 5,
                    'target' => 'src/Bar.php',
                    'proof' => [
                        'eager' => false,
                        'pure_declaration' => true,
                        'classes' => [
                            ['class' => 'App\Bar', 'via' => 'psr-4', 'prefix' => 'App\\', 'path' => 'src/Bar.php'],
                        ],
                    ],
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
                    'proof' => [
                        'eager' => false,
                        'pure_declaration' => true,
                        'classes' => [
                            [
                                'class' => 'App\Tests\TestSupport',
                                'via' => 'psr-4',
                                'prefix' => 'App\Tests\\',
                                'path' => 'dev-tests/TestSupport.php',
                            ],
                        ],
                    ],
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
                    'proof' => [
                        'eager' => false,
                        'pure_declaration' => true,
                        'classes' => [
                            ['class' => 'App\Pure', 'via' => 'psr-4', 'prefix' => 'App\\', 'path' => 'src/Pure.php'],
                        ],
                    ],
                ],
                // autoload.files entry, loaded eagerly.
                [
                    'file' => 'public/index.php',
                    'line' => 8,
                    'target' => 'src/eager.php',
                    'proof' => ['eager' => true, 'pure_declaration' => null, 'classes' => []],
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
                    'proof' => [
                        'eager' => false,
                        'pure_declaration' => true,
                        'classes' => [
                            ['class' => 'App\Reachable', 'via' => 'psr-4', 'prefix' => 'App\\', 'path' => 'src/Reachable.php'],
                        ],
                    ],
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
                    'class' => 'App\Sub\Missing',
                    'expected_path' => 'src/Sub/Missing.php',
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
                    'class' => 'App\Dup',
                    'loaded_from' => 'classmap/Dup.php',
                    'detail' => 'App\Dup is autoloaded from classmap/Dup.php'
                        . ' — this require loads a shadowed copy',
                ],
            ],
            $result['conflicting']
        );

        // src/helper.php declares no type: accounted for as "needed", absent
        // from the redundant/fixable/conflicting/unresolved sections.
        self::assertSame(
            [
                [
                    'file' => 'public/index.php',
                    'line' => 8,
                    'target' => 'src/helper.php',
                    'reason' => 'target declares no types',
                ],
            ],
            $result['needed']
        );
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
                    'proof' => ['eager' => true, 'pure_declaration' => null, 'classes' => []],
                ],
                [
                    'file' => 'public/second.php',
                    'line' => 8,
                    'target' => 'dev/dev-eager.php',
                    'proof' => ['eager' => true, 'pure_declaration' => null, 'classes' => []],
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
                    'class' => 'App\Sub\Gone',
                    'expected_path' => 'src/Sub/Gone.php',
                    'detail' => 'App\Sub\Gone would load from src/Sub/Gone.php'
                        . ' — fix autoload, then remove this require',
                ],
                [
                    'file' => 'public/second.php',
                    'line' => 5,
                    'target' => 'src/MixedMissing.php',
                    'class' => 'App\Sub\Gone',
                    'expected_path' => 'src/Sub/Gone.php',
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
                    'class' => 'App\Winner',
                    'loaded_from' => 'classmap/Winner.php',
                    'detail' => 'App\Winner is autoloaded from classmap/Winner.php'
                        . ' — this require loads a shadowed copy',
                ],
                [
                    'file' => 'public/index.php',
                    'line' => 9,
                    'target' => 'src/MixedBoth.php',
                    'class' => 'App\Winner2',
                    'loaded_from' => 'classmap/Winner2.php',
                    'detail' => 'App\Winner2 is autoloaded from classmap/Winner2.php'
                        . ' — this require loads a shadowed copy',
                ],
            ],
            $result['conflicting']
        );

        // Needed: MixedGlobal (declares an uncovered class, memoized on the
        // second require), the nonexistent DoesNotExist.php target (no
        // declarations), and the two side-effect files, one row per require
        // site — none of these are deletable, but none are silently dropped.
        self::assertSame(
            [
                [
                    'file' => 'public/index.php',
                    'line' => 7,
                    'target' => 'src/MixedGlobal.php',
                    'reason' => 'GlobalEdgeHelper is not covered by any autoload rule',
                ],
                [
                    'file' => 'public/second.php',
                    'line' => 6,
                    'target' => 'src/MixedGlobal.php',
                    'reason' => 'GlobalEdgeHelper is not covered by any autoload rule',
                ],
                [
                    'file' => 'public/second.php',
                    'line' => 7,
                    'target' => 'src/DoesNotExist.php',
                    'reason' => 'target file does not exist',
                ],
                [
                    'file' => 'public/second.php',
                    'line' => 9,
                    'target' => 'src/WithFunction.php',
                    'reason' => 'target declares types but also defines functions/constants or runs top-level code',
                ],
                [
                    'file' => 'public/second.php',
                    'line' => 10,
                    'target' => 'src/WithSideEffect.php',
                    'reason' => 'target declares types but also defines functions/constants or runs top-level code',
                ],
            ],
            $result['needed']
        );
        self::assertSame([], $result['unresolved']);

        // WithFunction and WithSideEffect each round-trip their class, but the
        // files also declare a function / a constant + top-level statement.
        // Autoload would not reproduce those, so the requires are load-bearing
        // and must appear in no actionable section.
        $allTargets = array_merge(
            array_column($result['redundant'], 'target'),
            array_column($result['fixable'], 'target'),
            array_column($result['conflicting'], 'target')
        );
        self::assertNotContains('src/WithFunction.php', $allTargets);
        self::assertNotContains('src/WithSideEffect.php', $allTargets);
    }

    public function testClassmapDuplicateBreaksTieTheWayComposerDoes(): void
    {
        // Dup is declared in both dup-a/One.php and dup-b/Two.php. Composer's
        // ClassMapGenerator keeps the FIRST occurrence (dup-a/, per
        // composer.json's classmap array order), so only the require of
        // dup-a/One.php round-trips; the require of dup-b/Two.php loads a
        // copy Composer never actually autoloads from.
        $projectRoot = $this->getFixturePath('ClassmapDuplicateProject');

        $result = (new Analyzer($projectRoot))->run();

        self::assertSame(
            [
                [
                    'file' => 'app.php',
                    'line' => 10,
                    'target' => 'dup-a/One.php',
                    'proof' => [
                        'eager' => false,
                        'pure_declaration' => true,
                        'classes' => [
                            ['class' => 'Dup', 'via' => 'classmap', 'prefix' => null, 'path' => 'dup-a/One.php'],
                        ],
                    ],
                ],
            ],
            $result['redundant']
        );

        self::assertSame(
            [
                [
                    'file' => 'app.php',
                    'line' => 9,
                    'target' => 'dup-b/Two.php',
                    'class' => 'Dup',
                    'loaded_from' => 'dup-a/One.php',
                    'detail' => 'Dup is autoloaded from dup-a/One.php — this require loads a shadowed copy',
                ],
            ],
            $result['conflicting']
        );
        self::assertSame([], $result['fixable']);
        self::assertSame([], $result['needed']);
        self::assertSame([], $result['unresolved']);
    }

    public function testEvaluatesExpressionsWithPhpSemantics(): void
    {
        // redefine.php: PHP's define() is first-wins, so LIB_ROOT keeps its
        // first value ('a'); a second define() of the same name is ignored.
        // closetag.php: the require statement ends with a close tag instead of
        // a semicolon, which must still resolve rather than become unresolved.
        $projectRoot = $this->getFixturePath('ExprSemanticsProject');

        $result = (new Analyzer($projectRoot))->run();

        self::assertContains(
            ['from' => 'redefine.php', 'line' => 8, 'type' => 'require_once', 'to' => 'a/x.php'],
            $result['edges']
        );
        self::assertContains(
            ['from' => 'closetag.php', 'line' => 1, 'type' => 'require_once', 'to' => 'inc.php'],
            $result['edges']
        );
        self::assertSame([], $result['unresolved']);
    }

    public function testSymlinkedRequireTargetIsNotFalselyConflicting(): void
    {
        // legacy/Widget.php is a symlink to src/Widget.php — the same file the
        // App\ PSR-4 rule autoloads. Lexically the two paths differ, but they
        // are one inode, so requiring the symlink round-trips at runtime and
        // must be redundant, not conflicting.
        if (!function_exists('symlink')) {
            self::markTestSkipped('symlink() is not available');
        }

        $root = sys_get_temp_dir() . '/depone_symlink_' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0777, true);
        mkdir($root . '/legacy', 0777, true);
        file_put_contents($root . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}');
        file_put_contents(
            $root . '/src/Widget.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nclass Widget\n{\n}\n"
        );
        file_put_contents($root . '/boot.php', "<?php\n\nrequire_once __DIR__ . '/legacy/Widget.php';\n");
        if (!@symlink($root . '/src/Widget.php', $root . '/legacy/Widget.php')) {
            $this->removeTree($root);
            self::markTestSkipped('symlink() failed on this filesystem');
        }

        try {
            $result = (new Analyzer($root))->run();

            self::assertSame([], $result['conflicting']);
            self::assertContains('legacy/Widget.php', array_column($result['redundant'], 'target'));
        } finally {
            $this->removeTree($root);
        }
    }

    private function removeTree(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            assert($item instanceof \SplFileInfo);
            // is_link() before is_dir(): a symlink to a directory must be
            // unlinked, not recursed into or rmdir'd.
            if ($item->isLink() || !$item->isDir()) {
                unlink($item->getPathname());
            } else {
                rmdir($item->getPathname());
            }
        }
        rmdir($dir);
    }

    public function testGuardedPolyfillDeclarationIsNeededNotConflicting(): void
    {
        // compat/polyfill.php declares App\Widget only behind a class_exists
        // guard; the real App\Widget lives at src/Widget.php. The guard makes
        // the require idempotent, so it must not be reported as loading a
        // shadowed copy (conflicting). It is needed: the file declares its type
        // only conditionally.
        $projectRoot = $this->getFixturePath('PolyfillProject');

        $result = (new Analyzer($projectRoot))->run();

        self::assertSame([], $result['conflicting']);
        self::assertSame([], $result['redundant']);
        self::assertSame(
            [
                [
                    'file' => 'boot.php',
                    'line' => 5,
                    'target' => 'compat/polyfill.php',
                    'reason' => 'target declares types only conditionally',
                ],
            ],
            $result['needed']
        );
        self::assertSame([], $result['unresolved']);
    }

    public function testActionableCategoriesConstantMatchesResultKeys(): void
    {
        $projectRoot = $this->getFixturePath('SampleProject');

        $result = (new Analyzer($projectRoot))->run();

        // The exit-code gate and the summary iterate ACTIONABLE_CATEGORIES, so
        // a category added to run() but not to the constant would be invisible
        // to both. Asserting the full key list makes that drift fail loudly.
        self::assertSame(
            [...Analyzer::ACTIONABLE_CATEGORIES, 'needed', 'unresolved', 'edges'],
            array_keys($result)
        );
    }

    private function getFixturePath(string $name): string
    {
        $path = realpath(__DIR__ . '/Fixture/' . $name);
        self::assertNotFalse($path);

        return $path;
    }
}
