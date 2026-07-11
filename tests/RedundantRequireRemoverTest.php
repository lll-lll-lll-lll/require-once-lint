<?php

declare(strict_types=1);

namespace Depone\Tests;

use Depone\Internal\Core\RedundantRequireRemover;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RedundantRequireRemover.
 *
 * Each test writes source into a throwaway directory, runs the remover against
 * an explicit set of redundant entries, and asserts the rewritten file — the
 * remover reads and writes files on disk.
 */
final class RedundantRequireRemoverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/depone_remover_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * @param list<array{file: string, line: int, target: string}> $redundant
     */
    private function fix(string $file, string $content, array $redundant): string
    {
        file_put_contents($this->root . '/' . $file, $content);
        $report = (new RedundantRequireRemover($this->root))->fix($redundant);
        // Expose the report for assertions that need it.
        $this->lastReport = $report;

        $result = file_get_contents($this->root . '/' . $file);
        self::assertIsString($result);

        return $result;
    }

    /** @var array{removed: list<array<string, mixed>>, skipped: list<array<string, mixed>>} */
    private array $lastReport;

    public function testRemovesSoloLineRedundantRequireWithoutLeavingABlankLine(): void
    {
        $content = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "require_once __DIR__ . '/src/Foo.php';\n"
            . "require_once __DIR__ . '/src/Bar.php';\n\n"
            . "echo 'hi';\n";

        $result = $this->fix('app.php', $content, [
            ['file' => 'app.php', 'line' => 5, 'target' => 'src/Foo.php'],
        ]);

        $expected = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "require_once __DIR__ . '/src/Bar.php';\n\n"
            . "echo 'hi';\n";
        self::assertSame($expected, $result);
        self::assertCount(1, $this->lastReport['removed']);
        self::assertSame([], $this->lastReport['skipped']);
    }

    public function testRemovesEveryRedundantRequireInOneFile(): void
    {
        $content = "<?php\n\n"
            . "require_once __DIR__ . '/a.php';\n"
            . "require_once __DIR__ . '/b.php';\n"
            . "require_once __DIR__ . '/c.php';\n";

        $result = $this->fix('app.php', $content, [
            ['file' => 'app.php', 'line' => 3, 'target' => 'a.php'],
            ['file' => 'app.php', 'line' => 5, 'target' => 'c.php'],
        ]);

        self::assertSame("<?php\n\nrequire_once __DIR__ . '/b.php';\n", $result);
        self::assertCount(2, $this->lastReport['removed']);
    }

    public function testSkipsRequireSharingALineWithOtherCode(): void
    {
        $content = "<?php\n\n\$x = 1; require_once __DIR__ . '/src/Foo.php';\n";

        $result = $this->fix('app.php', $content, [
            ['file' => 'app.php', 'line' => 3, 'target' => 'src/Foo.php'],
        ]);

        // Unchanged, and reported as skipped rather than partially rewritten.
        self::assertSame($content, $result);
        self::assertSame([], $this->lastReport['removed']);
        self::assertCount(1, $this->lastReport['skipped']);
    }

    public function testSkipsRequireWithATrailingComment(): void
    {
        $content = "<?php\n\nrequire_once __DIR__ . '/src/Foo.php'; // keep me\n";

        $result = $this->fix('app.php', $content, [
            ['file' => 'app.php', 'line' => 3, 'target' => 'src/Foo.php'],
        ]);

        self::assertSame($content, $result);
        self::assertCount(1, $this->lastReport['skipped']);
    }

    public function testRemovesAMultiLineRequireStatement(): void
    {
        $content = "<?php\n\n"
            . "require_once __DIR__\n    . '/src/Foo.php';\n"
            . "echo 'hi';\n";

        $result = $this->fix('app.php', $content, [
            ['file' => 'app.php', 'line' => 3, 'target' => 'src/Foo.php'],
        ]);

        self::assertSame("<?php\n\necho 'hi';\n", $result);
        self::assertCount(1, $this->lastReport['removed']);
    }

    public function testSkipsWhenTwoRequiresShareTheSameLine(): void
    {
        $content = "<?php\n\nrequire_once 'a.php'; require_once 'b.php';\n";

        $result = $this->fix('app.php', $content, [
            ['file' => 'app.php', 'line' => 3, 'target' => 'a.php'],
        ]);

        // Two require_once tokens on the line make the match ambiguous: leave it.
        self::assertSame($content, $result);
        self::assertCount(1, $this->lastReport['skipped']);
    }

    public function testSkipsRequireThatIsTheSoleBodyOfABracelessIf(): void
    {
        // Deleting the body would rebind run() as the if body — the file
        // still parses, so only the statement-position check protects it.
        $content = "<?php\nif (\$debug)\n    require_once __DIR__ . '/debug.php';\nrun();\n";

        $result = $this->fix('app.php', $content, [
            ['file' => 'app.php', 'line' => 3, 'target' => 'debug.php'],
        ]);

        self::assertSame($content, $result);
        self::assertSame([], $this->lastReport['removed']);
        self::assertCount(1, $this->lastReport['skipped']);
        self::assertSame(
            'body of a control structure or part of an expression',
            $this->lastReport['skipped'][0]['reason']
        );
    }

    public function testSkipsRequireThatIsTheSoleBodyOfABracelessElse(): void
    {
        $content = "<?php\nif (\$x)\n    a();\nelse\n    require_once 'b.php';\nc();\n";

        $result = $this->fix('app.php', $content, [
            ['file' => 'app.php', 'line' => 5, 'target' => 'b.php'],
        ]);

        self::assertSame($content, $result);
        self::assertSame([], $this->lastReport['removed']);
        self::assertCount(1, $this->lastReport['skipped']);
    }

    public function testSkipsRequireThatIsAnExpressionOperand(): void
    {
        // Deleting the require lines would graft the next statement onto the
        // dangling `return`, producing `return e();` — which still parses.
        $content = "<?php\nreturn\n    require_once 'a.php';\ne();\n";

        $result = $this->fix('app.php', $content, [
            ['file' => 'app.php', 'line' => 3, 'target' => 'a.php'],
        ]);

        self::assertSame($content, $result);
        self::assertSame([], $this->lastReport['removed']);
        self::assertCount(1, $this->lastReport['skipped']);
    }

    public function testDoesNotDoubleCountSkipsWhenTheReparseGuardFires(): void
    {
        // Line 2 is skipped at rewrite time (shares a line); line 4 is a
        // ternary else-branch on its own line, so it is cut — and the result
        // no longer parses, firing the re-parse guard. Each entry must be
        // reported exactly once.
        $content = "<?php\n\$a = 1; require_once 'x.php';\n\$b = \$c ? 'a' :\nrequire_once 'y.php';\n";

        $result = $this->fix('app.php', $content, [
            ['file' => 'app.php', 'line' => 2, 'target' => 'x.php'],
            ['file' => 'app.php', 'line' => 4, 'target' => 'y.php'],
        ]);

        self::assertSame($content, $result);
        self::assertSame([], $this->lastReport['removed']);
        self::assertCount(2, $this->lastReport['skipped']);
        self::assertSame(
            ['shares a line with other code or a comment', 'removal would not re-parse'],
            array_column($this->lastReport['skipped'], 'reason')
        );
    }

    public function testReportsEntriesInAnUnreadableFileAsSkippedAndContinues(): void
    {
        // Aborting on the unreadable file would leave app.php's fate
        // unreported; instead the failure lands in `skipped` and the other
        // file is still fixed.
        file_put_contents($this->root . '/locked.php', "<?php\nrequire_once 'a.php';\n");
        chmod($this->root . '/locked.php', 0o000);

        $result = $this->fix('app.php', "<?php\n\nrequire_once 'b.php';\n", [
            ['file' => 'app.php', 'line' => 3, 'target' => 'b.php'],
            ['file' => 'locked.php', 'line' => 2, 'target' => 'a.php'],
        ]);

        chmod($this->root . '/locked.php', 0o644);

        self::assertSame("<?php\n\n", $result);
        self::assertCount(1, $this->lastReport['removed']);
        self::assertCount(1, $this->lastReport['skipped']);
        self::assertSame('could not read the file', $this->lastReport['skipped'][0]['reason']);
        self::assertSame('locked.php', $this->lastReport['skipped'][0]['file']);
    }
}
