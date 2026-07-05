<?php

declare(strict_types=1);

namespace Depone\Tests;

use PHPUnit\Framework\TestCase;
use Depone\Internal\Cli\CliApplication;

/**
 * Integration tests for CliApplication.
 *
 * stdout/stderr are replaced with in-memory streams so exit codes and output
 * content can be asserted.
 * Fixture: tests/Fixture/CliApplicationProject/
 *   - public/index.php            : redundant require_once (src/Bar.php, a PSR-4 target)
 *   - public/index-with-const.php : unresolvable require_once (undefined constant), reason "complex"
 *   - src/Bar.php                 : PSR-4 autoload target
 * Fixture: tests/Fixture/DoctorProject/ (see AutoloadDoctorTest for the reason breakdown)
 *   Used by the `doctor` subcommand tests below.
 */
final class CliApplicationTest extends TestCase
{
    private static string $fixtureRoot;
    private static string $doctorFixtureRoot;

    public static function setUpBeforeClass(): void
    {
        $path = realpath(__DIR__ . '/Fixture/CliApplicationProject');
        self::assertNotFalse($path, 'CliApplicationProject fixture not found');
        self::$fixtureRoot = $path;

        $doctorPath = realpath(__DIR__ . '/Fixture/DoctorProject');
        self::assertNotFalse($doctorPath, 'DoctorProject fixture not found');
        self::$doctorFixtureRoot = $doctorPath;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Runs CliApplication and returns stdout, stderr, and the exit code.
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runApp(string ...$args): array
    {
        return $this->runAppInRoot(self::$fixtureRoot, ...$args);
    }

    /**
     * Runs CliApplication against the given repo root and returns stdout, stderr, and the exit code.
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runAppInRoot(string $repoRoot, string ...$args): array
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        self::assertNotFalse($stdout);
        self::assertNotFalse($stderr);

        $exitCode = (new CliApplication($stdout, $stderr, $repoRoot))(['bin', ...$args]);

        rewind($stdout);
        rewind($stderr);
        $result = [
            'exitCode' => $exitCode,
            'stdout'   => stream_get_contents($stdout),
            'stderr'   => stream_get_contents($stderr),
        ];
        fclose($stdout);
        fclose($stderr);

        return $result;
    }

    // -------------------------------------------------------------------------
    // --help / -h
    // -------------------------------------------------------------------------

    public function testHelpLongFlag(): void
    {
        $r = $this->runApp('--help');
        self::assertSame(0, $r['exitCode']);
        self::assertStringContainsString('Usage:', $r['stdout']);
        self::assertSame('', $r['stderr']);
    }

    public function testHelpShortFlag(): void
    {
        $r = $this->runApp('-h');
        self::assertSame(0, $r['exitCode']);
        self::assertStringContainsString('Usage:', $r['stdout']);
    }

    public function testHelpFlagWithSubcommandShowsThatSubcommandsHelp(): void
    {
        // `depone --help doctor` must display help for `doctor`, not be hijacked
        // into the default command's help by the default-command routing.
        $r = $this->runApp('--help', 'doctor');
        self::assertSame(0, $r['exitCode']);
        self::assertStringContainsString('Report files the Composer autoloader can never reach.', $r['stdout']);
        self::assertStringNotContainsString('Detect redundant require_once', $r['stdout']);
    }

    public function testGlobalOptionBeforeSubcommandStillRunsSubcommand(): void
    {
        // A global option ahead of the subcommand name (e.g. `--no-ansi doctor`)
        // must not cause the default command to be prepended and swallow the
        // subcommand as a stray argument.
        $r = $this->runAppInRoot(self::$doctorFixtureRoot, '--no-ansi', 'doctor');
        self::assertSame(0, $r['exitCode']);
        self::assertSame('', $r['stderr']);
        self::assertStringContainsString('autoload_unreachable_errors=', $r['stdout']);
    }

    // -------------------------------------------------------------------------
    // Default text output
    // -------------------------------------------------------------------------

    public function testDefaultTextOutputExitsZero(): void
    {
        $r = $this->runApp();
        self::assertSame(0, $r['exitCode']);
        self::assertSame('', $r['stderr']);
    }

    public function testDefaultTextOutputContainsSummaryKeys(): void
    {
        $r = $this->runApp();
        self::assertStringContainsString('redundant_require_once=', $r['stdout']);
        self::assertStringContainsString('unresolved_include_require=', $r['stdout']);
    }

    public function testRedundantRequireStatementDetectedInTextOutput(): void
    {
        $r = $this->runApp();
        // The require_once to src/Bar.php in public/index.php should be reported as redundant.
        self::assertStringContainsString('redundant_require_once=1', $r['stdout']);
        self::assertStringContainsString('src/Bar.php', $r['stdout']);
    }

    public function testUnresolvableConstantReportedWithComplexReason(): void
    {
        $r = $this->runApp();
        // public/index-with-const.php references an undefined constant, so it
        // cannot be statically resolved and is reported with reason "complex".
        self::assertStringContainsString('unresolved_include_require=1', $r['stdout']);
        self::assertStringContainsString('index-with-const.php:8 [complex] SITE_ROOT', $r['stdout']);
    }

    // -------------------------------------------------------------------------
    // --trace (text)
    // -------------------------------------------------------------------------

    public function testTraceTextOutput(): void
    {
        $r = $this->runApp('--trace', 'src/Bar.php');
        self::assertSame(0, $r['exitCode']);
        self::assertStringContainsString('trace_target=src/Bar.php', $r['stdout']);
        self::assertStringContainsString('direct_callers=', $r['stdout']);
        self::assertStringContainsString('public/index.php', $r['stdout']);
    }

    // -------------------------------------------------------------------------
    // doctor subcommand
    // -------------------------------------------------------------------------

    public function testDoctorDefaultExitsZeroAndPrintsOnlyErrorsSection(): void
    {
        // With no --min-severity, doctor reports errors alone; warnings and info
        // are frequently fixture-driven noise and must be opted into.
        $r = $this->runAppInRoot(self::$doctorFixtureRoot, 'doctor');
        self::assertSame(0, $r['exitCode']);
        self::assertSame('', $r['stderr']);
        self::assertStringContainsString('autoload_unreachable_errors=2', $r['stdout']);
        self::assertStringContainsString('src/Dup.php: App\Dup is shadowed by classmap/Dup.php', $r['stdout']);
        self::assertStringNotContainsString('autoload_unreachable_warnings=', $r['stdout']);
        self::assertStringNotContainsString('autoload_unreachable_info=', $r['stdout']);
    }

    public function testDoctorMinSeverityInfoPrintsAllThreeSections(): void
    {
        $r = $this->runAppInRoot(self::$doctorFixtureRoot, 'doctor', '--min-severity=info');
        self::assertSame(0, $r['exitCode']);
        self::assertSame('', $r['stderr']);
        self::assertStringContainsString('autoload_unreachable_errors=2', $r['stdout']);
        self::assertStringContainsString('autoload_unreachable_warnings=1', $r['stdout']);
        self::assertStringContainsString('autoload_unreachable_info=1', $r['stdout']);
        self::assertStringContainsString('src/Dup.php: App\Dup is shadowed by classmap/Dup.php', $r['stdout']);
    }

    public function testDoctorMinSeverityErrorPrintsOnlyErrorsSection(): void
    {
        $r = $this->runAppInRoot(self::$doctorFixtureRoot, 'doctor', '--min-severity=error');
        self::assertSame(0, $r['exitCode']);
        self::assertStringContainsString('autoload_unreachable_errors=2', $r['stdout']);
        self::assertStringNotContainsString('autoload_unreachable_warnings=', $r['stdout']);
        self::assertStringNotContainsString('autoload_unreachable_info=', $r['stdout']);
    }

    public function testDoctorMinSeverityWarningPrintsErrorsAndWarningsSections(): void
    {
        $r = $this->runAppInRoot(self::$doctorFixtureRoot, 'doctor', '--min-severity=warning');
        self::assertSame(0, $r['exitCode']);
        self::assertStringContainsString('autoload_unreachable_errors=2', $r['stdout']);
        self::assertStringContainsString('autoload_unreachable_warnings=1', $r['stdout']);
        self::assertStringNotContainsString('autoload_unreachable_info=', $r['stdout']);
    }

    public function testDoctorInvalidMinSeverityExitsOne(): void
    {
        $r = $this->runAppInRoot(self::$doctorFixtureRoot, 'doctor', '--min-severity=bogus');
        self::assertSame(1, $r['exitCode']);
        self::assertStringContainsString('bogus', $r['stderr']);
        self::assertSame('', $r['stdout']);
    }

    // -------------------------------------------------------------------------
    // Error cases
    // -------------------------------------------------------------------------

    public function testUnknownOptionExitsOne(): void
    {
        $r = $this->runApp('--no-such-option');
        self::assertSame(1, $r['exitCode']);
        self::assertStringContainsString('--no-such-option', $r['stderr']);
        self::assertSame('', $r['stdout']);
    }

    public function testAnalyzerExceptionExitsOne(): void
    {
        // Using a repoRoot without composer.json should surface an error.
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        self::assertNotFalse($stdout);
        self::assertNotFalse($stderr);

        $noComposerDir = __DIR__ . '/Fixture'; // directory without composer.json
        $exitCode = (new CliApplication($stdout, $stderr, $noComposerDir))(['bin']);

        rewind($stderr);
        $stderrContent = stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);

        self::assertSame(1, $exitCode);
        self::assertNotSame('', $stderrContent);
    }
}
