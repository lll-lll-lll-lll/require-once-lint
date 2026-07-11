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
 */
final class CliApplicationTest extends TestCase
{
    private static string $fixtureRoot;
    private static string $classificationFixtureRoot;

    public static function setUpBeforeClass(): void
    {
        $path = realpath(__DIR__ . '/Fixture/CliApplicationProject');
        self::assertNotFalse($path, 'CliApplicationProject fixture not found');
        self::$fixtureRoot = $path;

        $classificationPath = realpath(__DIR__ . '/Fixture/RequireClassificationProject');
        self::assertNotFalse($classificationPath, 'RequireClassificationProject fixture not found');
        self::$classificationFixtureRoot = $classificationPath;
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
     * Runs CliApplication against an explicit repository root.
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

    // -------------------------------------------------------------------------
    // Default text output
    // -------------------------------------------------------------------------

    public function testDefaultTextOutputExitsOneWhenFindingsExist(): void
    {
        // The fixture contains one redundant require, so the run reports
        // findings: exit code 1, nothing on stderr.
        $r = $this->runApp();
        self::assertSame(1, $r['exitCode']);
        self::assertSame('', $r['stderr']);
    }

    public function testExitsZeroWhenOnlyUnresolvedRequiresExist(): void
    {
        // CleanProject has no redundant/conflicting require, only a dynamic
        // include. unresolved entries are reported but deliberately never
        // affect the exit code.
        $cleanRoot = realpath(__DIR__ . '/Fixture/CleanProject');
        self::assertNotFalse($cleanRoot, 'CleanProject fixture not found');

        $r = $this->runAppInRoot($cleanRoot);
        self::assertSame(0, $r['exitCode']);
        self::assertSame('', $r['stderr']);
        self::assertStringContainsString('redundant_require_once=0', $r['stdout']);
        self::assertStringContainsString('conflicting_require_once=0', $r['stdout']);
        self::assertStringContainsString('unresolved_include_require=1', $r['stdout']);
    }

    public function testDefaultTextOutputContainsSummaryKeys(): void
    {
        $r = $this->runApp();
        self::assertStringContainsString('redundant_require_once=', $r['stdout']);
        self::assertStringContainsString('unresolved_include_require=', $r['stdout']);
        // The classification section is part of the output contract and must
        // be printed with a zero count even when nothing is found.
        self::assertStringContainsString('conflicting_require_once=0', $r['stdout']);
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
    // require_once classification (conflicting)
    // -------------------------------------------------------------------------

    public function testTextOutputClassifiesConflictingRequires(): void
    {
        $r = $this->runAppInRoot(self::$classificationFixtureRoot);
        self::assertSame(1, $r['exitCode']);
        self::assertSame('', $r['stderr']);

        self::assertStringContainsString('redundant_require_once=1', $r['stdout']);
        self::assertStringContainsString('public/index.php:5 => src/Reachable.php', $r['stdout']);

        self::assertStringContainsString('conflicting_require_once=1', $r['stdout']);
        self::assertStringContainsString(
            'src/Dup.php  (App\Dup is autoloaded from classmap/Dup.php'
                . ' — this require loads a shadowed copy)',
            $r['stdout']
        );

        // src/WrongPath.php (App\Sub\Missing derives a missing path) and
        // src/helper.php (no declared type) are load-bearing: both unreported.
        self::assertStringNotContainsString('src/WrongPath.php', $r['stdout']);
        self::assertStringNotContainsString('src/helper.php', $r['stdout']);
    }

    // -------------------------------------------------------------------------
    // --format json
    // -------------------------------------------------------------------------

    public function testJsonFormatOutput(): void
    {
        $r = $this->runApp('--format', 'json');

        // JSON is only an output format: the exit code still reflects findings.
        self::assertSame(1, $r['exitCode']);
        self::assertSame('', $r['stderr']);

        $decoded = json_decode($r['stdout'], true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('redundant', $decoded);
        self::assertArrayHasKey('conflicting', $decoded);
        self::assertArrayHasKey('unresolved', $decoded);
        // edges is informational and excluded, matching the text summary.
        self::assertArrayNotHasKey('edges', $decoded);

        self::assertSame(
            [['file' => 'public/index.php', 'line' => 5, 'target' => 'src/Bar.php']],
            $decoded['redundant']
        );
    }

    public function testJsonFormatTraceOutput(): void
    {
        $r = $this->runApp('--trace', 'src/Bar.php', '--format', 'json');

        self::assertSame(0, $r['exitCode']);
        $decoded = json_decode($r['stdout'], true);
        self::assertIsArray($decoded);
        self::assertSame('src/Bar.php', $decoded['target']);
        self::assertSame(['public/index.php'], $decoded['directCallers']);
    }

    public function testUnknownFormatExitsTwo(): void
    {
        $r = $this->runApp('--format', 'xml');
        self::assertSame(2, $r['exitCode']);
        self::assertStringContainsString('Unknown format', $r['stderr']);
        self::assertSame('', $r['stdout']);
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
    // --fix
    // -------------------------------------------------------------------------

    public function testFixRemovesRedundantRequireInPlace(): void
    {
        // --fix mutates files, so run it against a throwaway project copy.
        $root = sys_get_temp_dir() . '/depone_fix_' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}');
        file_put_contents(
            $root . '/src/Foo.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nclass Foo\n{\n}\n"
        );
        $entry = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "require_once __DIR__ . '/src/Foo.php';\n\n"
            . "echo 'hi';\n";
        file_put_contents($root . '/app.php', $entry);

        try {
            $r = $this->runAppInRoot($root, '--fix');

            self::assertSame(0, $r['exitCode']);
            self::assertSame('', $r['stderr']);
            self::assertStringContainsString('fixed_require_once=1', $r['stdout']);
            self::assertStringContainsString('app.php:5 => src/Foo.php', $r['stdout']);

            // The redundant require is gone; the rest of the file is untouched.
            $rewritten = file_get_contents($root . '/app.php');
            self::assertIsString($rewritten);
            self::assertStringNotContainsString('require_once', $rewritten);
            self::assertStringContainsString("echo 'hi';", $rewritten);
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
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // Error cases
    // -------------------------------------------------------------------------

    public function testUnknownOptionExitsTwo(): void
    {
        $r = $this->runApp('--no-such-option');
        self::assertSame(2, $r['exitCode']);
        self::assertStringContainsString('--no-such-option', $r['stderr']);
        self::assertSame('', $r['stdout']);
    }

    public function testAnalyzerExceptionExitsTwo(): void
    {
        // Using a repoRoot without composer.json should surface an error.
        $r = $this->runAppInRoot(__DIR__ . '/Fixture');
        self::assertSame(2, $r['exitCode']);
        self::assertNotSame('', $r['stderr']);
    }
}
