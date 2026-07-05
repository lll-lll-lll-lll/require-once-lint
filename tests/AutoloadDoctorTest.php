<?php

declare(strict_types=1);

namespace Depone\Tests;

use PHPUnit\Framework\TestCase;
use Depone\Internal\Core\AutoloadDoctor;
use Depone\Internal\Exception\AnalyzerException;

/**
 * Unit tests for AutoloadDoctor.
 *
 * Covered behavior: every finding reason lands in the expected severity bucket
 * with the expected file/detail, reachable classes produce no finding, and
 * eagerly-loaded `autoload.files` entries are exempt from `no_declarations`.
 *
 * Fixture: tests/Fixture/DoctorProject/
 *   - src/Reachable.php  : round-trips through App\ => src/, no finding
 *   - src/UsesConst.php  : reachable class that uses `::class`, no phantom finding
 *   - src/WrongPath.php  : App\Sub\Missing declared outside src/Sub/, expected_path_missing
 *   - src/Dup.php        : shadowed by classmap/Dup.php, resolved_elsewhere
 *   - classmap/Dup.php   : the winning classmap entry, no finding
 *   - src/GlobalClass.php: no namespace, matches no autoload rule
 *   - src/helper.php     : no type declarations
 *   - src/bootstrap.php  : no type declarations, but listed in autoload.files (exempt)
 */
final class AutoloadDoctorTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        $path = realpath(__DIR__ . '/Fixture/DoctorProject');
        self::assertNotFalse($path, 'DoctorProject fixture not found');
        self::$root = $path;
    }

    public function testReachableClassProducesNoFinding(): void
    {
        $result = (new AutoloadDoctor(self::$root))->run();

        foreach (['errors', 'warnings', 'info'] as $bucket) {
            foreach ($result[$bucket] as $finding) {
                self::assertNotSame('src/Reachable.php', $finding['file']);
            }
        }
    }

    public function testClassNameConstantDoesNotFabricatePhantomFinding(): void
    {
        // src/UsesConst.php is healthy but uses `UsesConst::class`; no finding of
        // any severity may reference it (a phantom would surface as an error).
        $result = (new AutoloadDoctor(self::$root))->run();

        foreach (['errors', 'warnings', 'info'] as $bucket) {
            foreach ($result[$bucket] as $finding) {
                self::assertNotSame('src/UsesConst.php', $finding['file']);
            }
        }
    }

    public function testWinningClassmapEntryProducesNoFinding(): void
    {
        $result = (new AutoloadDoctor(self::$root))->run();

        foreach (['errors', 'warnings', 'info'] as $bucket) {
            foreach ($result[$bucket] as $finding) {
                self::assertNotSame('classmap/Dup.php', $finding['file']);
            }
        }
    }

    public function testShadowedClassIsReportedAsResolvedElsewhereError(): void
    {
        $result = (new AutoloadDoctor(self::$root))->run();

        self::assertSame(
            [
                [
                    'severity' => 'error',
                    'reason' => 'resolved_elsewhere',
                    'file' => 'src/Dup.php',
                    'detail' => 'App\Dup is shadowed by classmap/Dup.php',
                ],
            ],
            array_values(array_filter($result['errors'], static fn (array $f): bool => $f['reason'] === 'resolved_elsewhere'))
        );
    }

    public function testWrongPathClassIsReportedAsExpectedPathMissingError(): void
    {
        $result = (new AutoloadDoctor(self::$root))->run();

        self::assertSame(
            [
                [
                    'severity' => 'error',
                    'reason' => 'expected_path_missing',
                    'file' => 'src/WrongPath.php',
                    'detail' => 'App\Sub\Missing would load from src/Sub/Missing.php — not found',
                ],
            ],
            array_values(array_filter($result['errors'], static fn (array $f): bool => $f['reason'] === 'expected_path_missing'))
        );
    }

    public function testGlobalClassIsReportedAsNoMatchingRuleWarning(): void
    {
        $result = (new AutoloadDoctor(self::$root))->run();

        self::assertSame(
            [
                [
                    'severity' => 'warning',
                    'reason' => 'no_matching_rule',
                    'file' => 'src/GlobalClass.php',
                    'detail' => 'GlobalClass matches no autoload rule',
                ],
            ],
            $result['warnings']
        );
    }

    public function testHelperFileWithoutDeclarationsIsReportedAsNoDeclarationsInfo(): void
    {
        $result = (new AutoloadDoctor(self::$root))->run();

        self::assertSame(
            [
                [
                    'severity' => 'info',
                    'reason' => 'no_declarations',
                    'file' => 'src/helper.php',
                    'detail' => 'no type declarations',
                ],
            ],
            $result['info']
        );
    }

    public function testEagerlyLoadedFileIsNotReportedAsNoDeclarations(): void
    {
        $result = (new AutoloadDoctor(self::$root))->run();

        foreach ($result['info'] as $finding) {
            self::assertNotSame('src/bootstrap.php', $finding['file']);
        }
    }

    public function testErrorsAreSortedByFileThenDetail(): void
    {
        $result = (new AutoloadDoctor(self::$root))->run();

        self::assertSame(
            ['src/Dup.php', 'src/WrongPath.php'],
            array_column($result['errors'], 'file')
        );
    }

    public function testMissingComposerJsonThrowsAnalyzerException(): void
    {
        $tmpDir = sys_get_temp_dir() . '/autoload_doctor_test_' . uniqid('', true);
        mkdir($tmpDir);
        try {
            $this->expectException(AnalyzerException::class);
            (new AutoloadDoctor($tmpDir))->run();
        } finally {
            rmdir($tmpDir);
        }
    }
}
