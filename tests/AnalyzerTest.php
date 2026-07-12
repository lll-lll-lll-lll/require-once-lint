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

        // src/WrongPath.php declares App\Sub\Missing, which matches the App\
        // rule but derives a missing path — not autoload-reachable, so the
        // require stays load-bearing and is reported in no section.
        $reportedTargets = array_merge(
            array_column($result['redundant'], 'target'),
            array_column($result['conflicting'], 'target')
        );
        self::assertNotContains('src/WrongPath.php', $reportedTargets);

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

        // MixedShadow: App\Winner is autoloaded elsewhere. MixedBoth declares
        // the not-autoload-reachable App\Sub\AlsoGone FIRST and the shadowed
        // App\Winner2 second: the conflict still wins over the target it shares
        // with a class that would otherwise keep the require merely load-bearing.
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
        // (no declarations, must not warn or crash), and the side-effect files
        // below.
        self::assertSame([], $result['unresolved']);

        // MixedMissing round-trips App\MixedMissing but App\Sub\Gone derives a
        // missing path; WithFunction and WithSideEffect round-trip their class
        // but also declare a function / a constant + top-level statement. In
        // every case autoload would not reproduce everything the target
        // provides, so the requires are load-bearing and appear in no section.
        $allTargets = array_merge(
            array_column($result['redundant'], 'target'),
            array_column($result['conflicting'], 'target')
        );
        self::assertNotContains('src/MixedMissing.php', $allTargets);
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
                    'detail' => 'Dup is autoloaded from dup-a/One.php — this require loads a shadowed copy',
                ],
            ],
            $result['conflicting']
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

    public function testGuardedPolyfillDeclarationIsNotFalselyConflicting(): void
    {
        // compat/polyfill.php declares App\Widget only behind a class_exists
        // guard; the real App\Widget lives at src/Widget.php. The guard makes
        // the require idempotent, so it must not be reported as loading a
        // shadowed copy (conflicting). Because the file declares its type only
        // conditionally, the require is load-bearing ("needed") and is reported
        // in no section.
        $projectRoot = $this->getFixturePath('PolyfillProject');

        $result = (new Analyzer($projectRoot))->run();

        self::assertSame([], $result['conflicting']);
        self::assertSame([], $result['redundant']);
        self::assertSame([], $result['unresolved']);
    }

    public function testConsidersDumpedDependencyAutoload(): void
    {
        // When Composer has dumped its autoloader, classification must consult
        // the merged root+dependency maps, not just the root composer.json:
        //   - legacy/DepShadow.php declares Acme\Lib\Thing, which the dependency
        //     (vendor/acme/lib) autoloads from its own PSR-4 rule → conflicting,
        //     invisible without reading the dumped vendor autoload.
        //   - vendor/acme/lib/bootstrap.php is a dependency autoload.files entry
        //     (in the dumped autoload_files.php) → redundant.
        //   - src/Widget.php round-trips via the dumped PSR-4 map, and
        //     src/eager.php is the root files entry → both redundant.
        $projectRoot = $this->getFixturePath('VendorAutoloadProject');

        $result = (new Analyzer($projectRoot))->run();

        self::assertSame(
            [
                [
                    'file' => 'public/index.php',
                    'line' => 5,
                    'target' => 'src/Widget.php',
                ],
                [
                    'file' => 'public/index.php',
                    'line' => 7,
                    'target' => 'src/eager.php',
                ],
                [
                    'file' => 'public/index.php',
                    'line' => 8,
                    'target' => 'vendor/acme/lib/bootstrap.php',
                ],
            ],
            $result['redundant']
        );

        self::assertSame(
            [
                [
                    'file' => 'public/index.php',
                    'line' => 6,
                    'target' => 'legacy/DepShadow.php',
                    'detail' => 'Acme\Lib\Thing is autoloaded from vendor/acme/lib/src/Thing.php'
                        . ' — this require loads a shadowed copy',
                ],
            ],
            $result['conflicting']
        );
        self::assertSame([], $result['unresolved']);
    }

    // -------------------------------------------------------------------------
    // kept inventory (why load-bearing requires cannot be removed)
    // -------------------------------------------------------------------------

    public function testKeptInventoryRecordsWhyRequiresAreLoadBearing(): void
    {
        // The requires left out of the findings are not dropped: the kept
        // inventory records, per target, the reasons they are load-bearing —
        // an unreachable class, or the side effects autoload cannot reproduce.
        $projectRoot = $this->getFixturePath('RequireClassificationProject');

        $result = (new Analyzer($projectRoot))->run();

        self::assertSame(
            [
                [
                    'target' => 'src/WrongPath.php',
                    'requiredFrom' => [['file' => 'public/index.php', 'line' => 6]],
                    'reasons' => ['class_not_autoloadable'],
                    'sideEffects' => [],
                    'unreachableClasses' => ['App\Sub\Missing'],
                ],
                [
                    'target' => 'src/helper.php',
                    'requiredFrom' => [['file' => 'public/index.php', 'line' => 8]],
                    'reasons' => ['no_types', 'side_effects'],
                    'sideEffects' => [
                        [
                            'kind' => 'function',
                            'line' => 7,
                            'excerpt' => "function require_classification_helper(): string { return 'helper'; }",
                        ],
                    ],
                    'unreachableClasses' => [],
                ],
            ],
            $result['kept']
        );
    }

    public function testKeptInventoryReportsGuardedPolyfillAsItsOwnReason(): void
    {
        // A polyfill declares its type only behind a class_exists guard —
        // kept for a different reason than a file declaring no types at all.
        $projectRoot = $this->getFixturePath('PolyfillProject');

        $result = (new Analyzer($projectRoot))->run();

        self::assertCount(1, $result['kept']);
        $entry = $result['kept'][0];
        self::assertSame('compat/polyfill.php', $entry['target']);
        self::assertSame(['guarded_declarations_only', 'side_effects'], $entry['reasons']);
        self::assertSame('control_flow', $entry['sideEffects'][0]['kind']);
        self::assertSame([], $entry['unreachableClasses']);
    }

    public function testKeptInventoryReportsMissingTargetsAndAggregatesCallers(): void
    {
        $projectRoot = $this->getFixturePath('RequireClassificationEdgeProject');

        $result = (new Analyzer($projectRoot))->run();

        $byTarget = array_column($result['kept'], null, 'target');

        // A require whose target does not exist is kept, with the reason.
        self::assertSame(['target_missing'], $byTarget['src/DoesNotExist.php']['reasons']);

        // MixedMissing is required from both entrypoints: one aggregated
        // entry, callers sorted by file and line.
        self::assertSame(
            [
                ['file' => 'public/index.php', 'line' => 6],
                ['file' => 'public/second.php', 'line' => 5],
            ],
            $byTarget['src/MixedMissing.php']['requiredFrom']
        );
        self::assertSame(['class_not_autoloadable'], $byTarget['src/MixedMissing.php']['reasons']);
        self::assertSame(['App\Sub\Gone'], $byTarget['src/MixedMissing.php']['unreachableClasses']);
    }

    public function testKeptInventoryExcludesVendorTargets(): void
    {
        // require_once vendor/autoload.php is load-bearing in every project,
        // but vendor code is not the user's to migrate: it stays out of the
        // inventory, while the user's own kept require is listed.
        $root = sys_get_temp_dir() . '/depone_vendor_kept_' . bin2hex(random_bytes(6));
        mkdir($root . '/vendor', 0777, true);
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/composer.json', '{}');
        file_put_contents(
            $root . '/vendor/autoload.php',
            "<?php\n\nrequire __DIR__ . '/composer/autoload_real.php';\n"
        );
        file_put_contents($root . '/src/helpers.php', "<?php\n\nfunction helper(): void\n{\n}\n");
        file_put_contents(
            $root . '/index.php',
            "<?php\n\nrequire_once __DIR__ . '/vendor/autoload.php';\nrequire_once __DIR__ . '/src/helpers.php';\n"
        );

        try {
            $result = (new Analyzer($root))->run();

            self::assertSame(['src/helpers.php'], array_column($result['kept'], 'target'));
        } finally {
            $this->removeTree($root);
        }
    }

    public function testActionableCategoriesConstantMatchesResultKeys(): void
    {
        $projectRoot = $this->getFixturePath('SampleProject');

        $result = (new Analyzer($projectRoot))->run();

        // The exit-code gate and the summary iterate ACTIONABLE_CATEGORIES, so
        // a category added to run() but not to the constant would be invisible
        // to both. Asserting the full key list makes that drift fail loudly.
        // `unresolved`, `kept`, and `edges` are informational by design.
        self::assertSame(
            [...Analyzer::ACTIONABLE_CATEGORIES, 'unresolved', 'kept', 'edges'],
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
