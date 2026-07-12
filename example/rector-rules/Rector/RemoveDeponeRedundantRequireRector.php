<?php

declare(strict_types=1);

namespace Example\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeVisitor;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\Rector\AbstractRector;

/**
 * Deletes the require_once statements a depone JSON report classified as
 * `redundant` — the category whose contract is "deleting this is provably
 * safe". depone is the oracle (whole-project autoload analysis), Rector is
 * the executor (AST-safe removal with format preservation and --dry-run).
 *
 * The join key is the report entry's repo-relative file plus the statement's
 * start line, and a statement is only ever deleted when it actually is a
 * require_once at that position — so a stale report can at worst skip a
 * deletion, never remove the wrong code. `conflicting` and `unresolved`
 * entries are deliberately not consumed: they need a human.
 */
final class RemoveDeponeRedundantRequireRector extends AbstractRector implements ConfigurableRectorInterface
{
    public const REPORT_PATH = 'reportPath';
    public const REPO_ROOT = 'repoRoot';

    /**
     * How many redundant entries the report holds per file and line. Each
     * deletion consumes one, so a line carrying two require_once statements
     * with a single report entry deletes at most one of them instead of both.
     *
     * @var array<string, array<int, int>>
     */
    private array $redundant = [];

    private string $repoRoot = '';

    /**
     * @param mixed[] $configuration
     */
    public function configure(array $configuration): void
    {
        $reportPath = $configuration[self::REPORT_PATH] ?? null;
        $repoRoot = $configuration[self::REPO_ROOT] ?? null;
        if (!is_string($reportPath) || !is_string($repoRoot)) {
            throw new \InvalidArgumentException(sprintf(
                '%s needs the "%s" and "%s" options.',
                self::class,
                self::REPORT_PATH,
                self::REPO_ROOT
            ));
        }
        $this->repoRoot = rtrim(str_replace('\\', '/', $repoRoot), '/');

        if (!is_file($reportPath)) {
            throw new \RuntimeException(sprintf(
                'depone report not found at %s — generate it first: vendor/bin/depone --format json > %s',
                $reportPath,
                $reportPath
            ));
        }

        $report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($report) || !is_array($report['redundant'] ?? null)) {
            throw new \RuntimeException(sprintf('%s does not look like a depone --format json report.', $reportPath));
        }

        foreach ($report['redundant'] as $entry) {
            $file = $entry['file'] ?? null;
            $line = $entry['line'] ?? null;
            if (is_string($file) && is_int($line)) {
                $this->redundant[$file][$line] = ($this->redundant[$file][$line] ?? 0) + 1;
            }
        }
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Expression::class];
    }

    /**
     * @param Expression $node
     */
    public function refactor(Node $node): ?int
    {
        if (!$node->expr instanceof Include_ || $node->expr->type !== Include_::TYPE_REQUIRE_ONCE) {
            return null;
        }

        $file = $this->relativeFilePath();
        $line = $node->getStartLine();
        if (($this->redundant[$file][$line] ?? 0) < 1) {
            return null;
        }

        --$this->redundant[$file][$line];

        return NodeVisitor::REMOVE_NODE;
    }

    private function relativeFilePath(): string
    {
        $absolute = str_replace('\\', '/', $this->file->getFilePath());
        $prefix = $this->repoRoot . '/';

        return str_starts_with($absolute, $prefix) ? substr($absolute, strlen($prefix)) : $absolute;
    }
}
