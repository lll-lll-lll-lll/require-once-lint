<?php

declare(strict_types=1);

namespace Depone\Tests;

use PHPUnit\Framework\TestCase;
use Depone\Internal\Exception\AnalyzerException;
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
    // classmap duplicates
    // -------------------------------------------------------------------------

    public function testDuplicateClassAcrossTwoScannedDirsResolvesToFirstEntry(): void
    {
        // Dup is declared in both dup-a/One.php and dup-b/Two.php; composer.json
        // lists dup-a/ before dup-b/. Composer's ClassMapGenerator keeps the
        // first occurrence, so resolve() must return dup-a/One.php, not
        // whichever file the filesystem happened to scan last.
        $path = realpath(__DIR__ . '/Fixture/ClassmapDuplicateProject');
        self::assertNotFalse($path, 'Fixture directory not found');

        $resolver = new AutoloadResolver($path);

        self::assertSame($path . '/dup-a/One.php', $resolver->resolve('Dup'));
    }

    // -------------------------------------------------------------------------
    // Dumped autoloader (vendor/composer/autoload_*.php)
    // -------------------------------------------------------------------------

    public function testResolvesDependencyClassFromDumpedAutoload(): void
    {
        // With a dumped autoloader present, PSR-4 rules of installed
        // dependencies resolve too: Acme\Lib\Thing comes from the dependency's
        // rule in vendor/composer/autoload_psr4.php, not the root composer.json.
        $path = realpath(__DIR__ . '/Fixture/VendorAutoloadProject');
        self::assertNotFalse($path, 'Fixture directory not found');

        $resolver = new AutoloadResolver($path);

        self::assertSame($path . '/vendor/acme/lib/src/Thing.php', $resolver->resolve('Acme\Lib\Thing'));
        // A root PSR-4 rule from the dumped map still resolves.
        self::assertSame($path . '/src/Widget.php', $resolver->resolve('App\Widget'));
    }

    public function testCorruptDumpedMapSurfacesAsAnalyzerException(): void
    {
        // Loading a dumped map executes it; a truncated file (e.g. an
        // interrupted `composer dump-autoload`) must surface as a clean
        // analysis error, not an uncaught ParseError.
        $root = sys_get_temp_dir() . '/depone_corrupt_' . bin2hex(random_bytes(6));
        mkdir($root . '/vendor/composer', 0777, true);
        file_put_contents($root . '/vendor/composer/autoload_psr4.php', "<?php\nreturn array(\n");

        try {
            new AutoloadResolver($root);
            self::fail('Expected AnalyzerException');
        } catch (AnalyzerException $e) {
            self::assertStringContainsString('autoload_psr4.php', $e->getMessage());
        } finally {
            unlink($root . '/vendor/composer/autoload_psr4.php');
            rmdir($root . '/vendor/composer');
            rmdir($root . '/vendor');
            rmdir($root);
        }
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
