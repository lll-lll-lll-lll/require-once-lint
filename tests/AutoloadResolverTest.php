<?php

declare(strict_types=1);

namespace Depone\Tests;

use PHPUnit\Framework\TestCase;
use Depone\Internal\Resolver\AutoloadResolver;

/**
 * Unit tests for AutoloadResolver.
 *
 * Covered behavior:
 *   PSR-4 resolution (basic, subdirectory, longer prefix wins)
 *   PSR-0 resolution (underscore-to-slash conversion, namespaced forms)
 *   classmap resolution (class / interface / trait)
 *   classmap taking precedence over PSR-4
 *   loading rules from autoload-dev
 *   stripping a leading backslash
 *   returning null when composer.json is missing
 *   resolveVerbose: matched prefix / expected path / resolved path triples
 *
 * Fixture: tests/Fixture/AutoloadResolverProject/
 */
final class AutoloadResolverTest extends TestCase
{
    private static string $root;
    private static AutoloadResolver $resolver;

    public static function setUpBeforeClass(): void
    {
        $path = realpath(__DIR__ . '/Fixture/AutoloadResolverProject');
        self::assertNotFalse($path, 'Fixture directory not found');
        self::$root = $path;
        self::$resolver = new AutoloadResolver($path);
    }

    // -------------------------------------------------------------------------
    // PSR-4 resolution
    // -------------------------------------------------------------------------

    public function testPsr4BasicResolution(): void
    {
        $result = self::$resolver->resolve('App\Foo');
        self::assertSame(self::$root . '/src/Foo.php', $result);
    }

    public function testPsr4SubdirectoryResolution(): void
    {
        $result = self::$resolver->resolve('App\Sub\Bar');
        self::assertSame(self::$root . '/src/Sub/Bar.php', $result);
    }

    public function testPsr4InterfaceResolution(): void
    {
        // Interfaces should resolve through PSR-4 path rules as well.
        $result = self::$resolver->resolve('App\Contracts\MyInterface');
        self::assertSame(self::$root . '/src/Contracts/MyInterface.php', $result);
    }

    public function testPsr4TraitResolution(): void
    {
        // Traits should also resolve through PSR-4 path rules.
        $result = self::$resolver->resolve('App\Contracts\MyTrait');
        self::assertSame(self::$root . '/src/Contracts/MyTrait.php', $result);
    }

    public function testPsr4LeadingBackslashIsStripped(): void
    {
        // \App\Foo should resolve the same as App\Foo.
        $result = self::$resolver->resolve('\App\Foo');
        self::assertSame(self::$root . '/src/Foo.php', $result);
    }

    public function testPsr4NonExistentFileReturnsNull(): void
    {
        // Classes whose files do not exist should return null.
        self::assertNull(self::$resolver->resolve('App\DoesNotExist'));
    }

    public function testPsr4NoPrefixMatchReturnsNull(): void
    {
        // Classes with no matching prefix should return null.
        self::assertNull(self::$resolver->resolve('Unknown\SomeClass'));
    }

    public function testPsr4LongerPrefixTakesPriority(): void
    {
        // When both App\ and App\Specific\ exist, the longer prefix wins.
        // App\Specific\SpecificFoo -> src/specific/SpecificFoo.php
        // The broader App\ rule would look for src/Specific/SpecificFoo.php instead.
        $result = self::$resolver->resolve('App\Specific\SpecificFoo');
        self::assertSame(self::$root . '/src/specific/SpecificFoo.php', $result);
    }

    public function testPsr4MultipleDirectoriesResolveEarlierPath(): void
    {
        $result = self::$resolver->resolve('Multi\FromA');
        self::assertSame(self::$root . '/multi-a/FromA.php', $result);
    }

    public function testPsr4MultipleDirectoriesResolveLaterPath(): void
    {
        $result = self::$resolver->resolve('Multi\FromB');
        self::assertSame(self::$root . '/multi-b/FromB.php', $result);
    }

    // -------------------------------------------------------------------------
    // PSR-0 resolution
    // -------------------------------------------------------------------------

    public function testPsr0UnderscoreConvertedToSlash(): void
    {
        // Legacy_Component -> legacy/Legacy/Component.php
        // PSR-0 converts underscores in the class name to directory separators.
        $result = self::$resolver->resolve('Legacy_Component');
        self::assertSame(self::$root . '/legacy/Legacy/Component.php', $result);
    }

    public function testPsr0WithNamespaceAndUnderscore(): void
    {
        // Namespaced PSR-0 form:
        //   Legacy\Sub\Class_Name -> legacy/ + Legacy/Sub/ + Class/Name.php
        // This file does not exist, so the result should be null.
        self::assertNull(self::$resolver->resolve('Legacy\Sub\Class_Name'));
    }

    // -------------------------------------------------------------------------
    // classmap resolution
    // -------------------------------------------------------------------------

    public function testClassmapClassResolution(): void
    {
        $result = self::$resolver->resolve('Mapped\MappedClass');
        self::assertSame(self::$root . '/classmap/MappedClass.php', $result);
    }

    public function testClassmapInterfaceIsDetected(): void
    {
        // Interfaces detected by scanFileForClasses should be added to the classmap.
        $result = self::$resolver->resolve('Mapped\MappedInterface');
        self::assertSame(self::$root . '/classmap/MappedInterface.php', $result);
    }

    public function testClassmapTraitIsDetected(): void
    {
        // Traits detected by scanFileForClasses should be added to the classmap.
        $result = self::$resolver->resolve('Mapped\MappedTrait');
        self::assertSame(self::$root . '/classmap/MappedTrait.php', $result);
    }

    public function testClassmapTakesPriorityOverPsr4(): void
    {
        // App\OverriddenClass is resolvable via PSR-4 as well, but the classmap
        // entry should win because it is checked first.
        $result = self::$resolver->resolve('App\OverriddenClass');
        self::assertSame(self::$root . '/classmap/OverriddenClass.php', $result);
        // Ensure the PSR-4 file is not returned instead.
        self::assertNotSame(self::$root . '/src/OverriddenClass.php', $result);
    }

    // -------------------------------------------------------------------------
    // autoload-dev loading
    // -------------------------------------------------------------------------

    public function testAutoloadDevClassIsResolved(): void
    {
        // The autoload-dev PSR-4 rule (App\Tests\ -> dev-tests/) should also work.
        $result = self::$resolver->resolve('App\Tests\FooTest');
        self::assertSame(self::$root . '/dev-tests/FooTest.php', $result);
    }

    // -------------------------------------------------------------------------
    // resolveVerbose
    // -------------------------------------------------------------------------

    public function testResolveVerbosePsr4RoundTrip(): void
    {
        self::assertSame(
            [
                'prefix' => 'App\\',
                'expectedPath' => self::$root . '/src/Foo.php',
                'resolved' => self::$root . '/src/Foo.php',
            ],
            self::$resolver->resolveVerbose('App\Foo')
        );
    }

    public function testResolveVerbosePsr4MissingFileKeepsExpectedPath(): void
    {
        // The rule matches but the derived file does not exist: the expected
        // path is what a fixable_require_once detail is built from.
        self::assertSame(
            [
                'prefix' => 'App\\',
                'expectedPath' => self::$root . '/src/DoesNotExist.php',
                'resolved' => null,
            ],
            self::$resolver->resolveVerbose('App\DoesNotExist')
        );
    }

    public function testResolveVerboseLongerPrefixWinsForExpectedPath(): void
    {
        self::assertSame(
            [
                'prefix' => 'App\Specific\\',
                'expectedPath' => self::$root . '/src/specific/SpecificFoo.php',
                'resolved' => self::$root . '/src/specific/SpecificFoo.php',
            ],
            self::$resolver->resolveVerbose('App\Specific\SpecificFoo')
        );
    }

    public function testResolveVerboseMultiDirExpectedPathUsesFirstDirectory(): void
    {
        // Multi\FromB actually resolves from multi-b/, but the expected path is
        // always derived from the rule's first directory. The two may diverge;
        // resolved is the ground truth, expectedPath is the canonical location.
        self::assertSame(
            [
                'prefix' => 'Multi\\',
                'expectedPath' => self::$root . '/multi-a/FromB.php',
                'resolved' => self::$root . '/multi-b/FromB.php',
            ],
            self::$resolver->resolveVerbose('Multi\FromB')
        );
    }

    public function testResolveVerbosePsr0RoundTrip(): void
    {
        // PSR-0: underscores in the class name map to directory separators.
        self::assertSame(
            [
                'prefix' => 'Legacy_',
                'expectedPath' => self::$root . '/legacy/Legacy/Component.php',
                'resolved' => self::$root . '/legacy/Legacy/Component.php',
            ],
            self::$resolver->resolveVerbose('Legacy_Component')
        );
    }

    public function testResolveVerbosePsr0MissingFileKeepsExpectedPath(): void
    {
        self::assertSame(
            [
                'prefix' => 'Legacy_',
                'expectedPath' => self::$root . '/legacy/Legacy/Missing/Widget.php',
                'resolved' => null,
            ],
            self::$resolver->resolveVerbose('Legacy_Missing_Widget')
        );
    }

    public function testResolveVerboseNoMatchingRuleIsAllNull(): void
    {
        self::assertSame(
            ['prefix' => null, 'expectedPath' => null, 'resolved' => null],
            self::$resolver->resolveVerbose('Unknown\SomeClass')
        );
    }

    public function testResolveVerboseClassmapResolvesWithoutPrefixOrExpectedPath(): void
    {
        // A classmap-only class resolves, but no PSR rule matched: there is no
        // prefix and no canonical expected path to report.
        self::assertSame(
            [
                'prefix' => null,
                'expectedPath' => null,
                'resolved' => self::$root . '/classmap/MappedClass.php',
            ],
            self::$resolver->resolveVerbose('Mapped\MappedClass')
        );
    }

    // -------------------------------------------------------------------------
    // Missing composer.json
    // -------------------------------------------------------------------------

    public function testMissingComposerJsonResultsInNullResolve(): void
    {
        $tmpDir = sys_get_temp_dir() . '/autoload_resolver_test_' . uniqid('', true);
        mkdir($tmpDir);
        try {
            $resolver = new AutoloadResolver($tmpDir);
            self::assertNull($resolver->resolve('Any\Class'));
        } finally {
            rmdir($tmpDir);
        }
    }
}
