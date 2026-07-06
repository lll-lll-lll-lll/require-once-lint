<?php

declare(strict_types=1);

namespace Depone\Tests;

use PHPUnit\Framework\TestCase;
use Depone\Internal\Exception\AnalyzerException;
use Depone\Internal\Resolver\ComposerLoaderVerifier;

/**
 * Unit tests for ComposerLoaderVerifier.
 *
 * Fixture: tests/Fixture/VerifyProject/
 *   - App\Ok    : dumped psr4 map agrees with composer.json -> verified
 *   - App\Stale : dumped classmap pins it to legacy/Stale.php -> mismatch
 *   - Lib\Thing : dumped psr4 map omits the Lib\ prefix entirely -> unknown
 */
final class ComposerLoaderVerifierTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        $path = realpath(__DIR__ . '/Fixture/VerifyProject');
        self::assertNotFalse($path, 'VerifyProject fixture not found');
        self::$root = $path;
    }

    public function testIsAvailableTrueWhenDumpedMapsExist(): void
    {
        self::assertTrue(ComposerLoaderVerifier::isAvailable(self::$root));
    }

    public function testIsAvailableFalseWithoutVendorDirectory(): void
    {
        $path = realpath(__DIR__ . '/Fixture/CliApplicationProject');
        self::assertNotFalse($path, 'CliApplicationProject fixture not found');
        self::assertFalse(ComposerLoaderVerifier::isAvailable($path));
    }

    public function testVerifyClassReportsVerifiedWhenDumpAgrees(): void
    {
        $verifier = new ComposerLoaderVerifier(self::$root);

        self::assertSame(
            ['status' => 'verified', 'loaderPath' => null],
            $verifier->verifyClass('App\Ok', self::$root . '/src/Ok.php')
        );
    }

    public function testVerifyClassReportsMismatchWhenDumpDisagrees(): void
    {
        $verifier = new ComposerLoaderVerifier(self::$root);

        $result = $verifier->verifyClass('App\Stale', self::$root . '/src/Stale.php');
        self::assertSame('mismatch', $result['status']);
        self::assertSame(self::$root . '/legacy/Stale.php', $result['loaderPath']);
    }

    public function testVerifyClassReportsUnknownWhenAbsentFromDump(): void
    {
        $verifier = new ComposerLoaderVerifier(self::$root);

        self::assertSame(
            ['status' => 'unknown', 'loaderPath' => null],
            $verifier->verifyClass('Lib\Thing', self::$root . '/lib/Thing.php')
        );
    }

    public function testVerifyEagerTargetTrueForDumpedFilesEntry(): void
    {
        $verifier = new ComposerLoaderVerifier(self::$root);

        self::assertTrue($verifier->verifyEagerTarget(self::$root . '/src/eager.php'));
    }

    public function testVerifyEagerTargetFalseForOtherFiles(): void
    {
        $verifier = new ComposerLoaderVerifier(self::$root);

        self::assertFalse($verifier->verifyEagerTarget(self::$root . '/src/Ok.php'));
    }

    public function testConstructingWithoutVendorMapsThrowsAnalyzerException(): void
    {
        $path = realpath(__DIR__ . '/Fixture/CliApplicationProject');
        self::assertNotFalse($path, 'CliApplicationProject fixture not found');

        $this->expectException(AnalyzerException::class);
        new ComposerLoaderVerifier($path);
    }

    public function testVerifyFindingsAnnotatesEntriesAndStampsMismatchesPerSite(): void
    {
        $verifier = new ComposerLoaderVerifier(self::$root);

        $okProof = ['eager' => false, 'pure_declaration' => true, 'classes' => [
            ['class' => 'App\Ok', 'via' => 'psr-4', 'prefix' => 'App\\', 'path' => 'src/Ok.php'],
        ]];
        $staleProof = ['eager' => false, 'pure_declaration' => true, 'classes' => [
            ['class' => 'App\Stale', 'via' => 'psr-4', 'prefix' => 'App\\', 'path' => 'src/Stale.php'],
        ]];
        $thingProof = ['eager' => false, 'pure_declaration' => true, 'classes' => [
            ['class' => 'Lib\Thing', 'via' => 'psr-4', 'prefix' => 'Lib\\', 'path' => 'lib/Thing.php'],
        ]];

        // src/Stale.php is required from two sites: the memo must compute its
        // verification once and stamp it onto both.
        $redundant = [
            ['file' => 'public/index.php', 'line' => 5, 'target' => 'src/Ok.php', 'proof' => $okProof],
            ['file' => 'public/index.php', 'line' => 6, 'target' => 'src/Stale.php', 'proof' => $staleProof],
            ['file' => 'public/other.php', 'line' => 3, 'target' => 'src/Stale.php', 'proof' => $staleProof],
            ['file' => 'public/index.php', 'line' => 7, 'target' => 'lib/Thing.php', 'proof' => $thingProof],
        ];

        $result = $verifier->verifyFindings($redundant, self::$root);

        self::assertSame(
            [true, false, false, false],
            array_column($result['entries'], 'verified')
        );

        self::assertCount(3, $result['mismatches']);
        [$staleAtIndex, $staleAtOther, $thingMismatch] = $result['mismatches'];

        self::assertSame(
            [
                'file' => 'public/index.php',
                'line' => 6,
                'target' => 'src/Stale.php',
                'class' => 'App\Stale',
                'loader_path' => 'legacy/Stale.php',
                'reason' => 'composer loader resolves a different file',
            ],
            $staleAtIndex
        );
        self::assertSame(
            [
                'file' => 'public/other.php',
                'line' => 3,
                'target' => 'src/Stale.php',
                'class' => 'App\Stale',
                'loader_path' => 'legacy/Stale.php',
                'reason' => 'composer loader resolves a different file',
            ],
            $staleAtOther
        );
        self::assertSame(
            [
                'file' => 'public/index.php',
                'line' => 7,
                'target' => 'lib/Thing.php',
                'class' => 'Lib\Thing',
                'loader_path' => null,
                'reason' => 'class not present in composer\'s dumped autoload — run composer dump-autoload',
            ],
            $thingMismatch
        );
    }
}
