<?php

declare(strict_types=1);

namespace Depone\Internal\Core;

/**
 * Formats analysis output.
 *
 * @phpstan-import-type AnalysisResult from \Depone\Internal\Core\Analyzer
 * @phpstan-import-type TraceResult from \Depone\Internal\Core\DependencyGraph
 * @phpstan-import-type TracePath from \Depone\Internal\Core\DependencyGraph
 * @phpstan-import-type FixReport from \Depone\Internal\Core\RedundantRequireRemover
 *
 * @internal
 */
final class OutputFormatter
{
    /**
     * Formats the result of a `--fix` run: the redundant require_once
     * statements removed, and any that were left in place (with a reason).
     *
     * @param FixReport $report
     */
    public function formatFixReport(array $report): string
    {
        $output = 'fixed_require_once=' . count($report['removed']) . PHP_EOL;
        foreach ($report['removed'] as $row) {
            $output .= "{$row['file']}:{$row['line']} => {$row['target']}" . PHP_EOL;
        }
        $output .= PHP_EOL;
        $output .= 'skipped_require_once=' . count($report['skipped']) . PHP_EOL;
        foreach ($report['skipped'] as $row) {
            $output .= "  {$row['file']}:{$row['line']} => {$row['target']}  ({$row['reason']})" . PHP_EOL;
        }

        return $output;
    }

    /**
     * Formats a text summary of the analysis result.
     *
     * @param AnalysisResult $result
     */
    public function formatSummary(array $result): string
    {
        $output = '';
        foreach (Analyzer::ACTIONABLE_CATEGORIES as $category) {
            $output .= "{$category}_require_once=" . count($result[$category]) . PHP_EOL;
            foreach ($result[$category] as $row) {
                // The text output is a frozen contract: redundant rows print
                // unindented with no detail, classified rows indented with one.
                $output .= isset($row['detail'])
                    ? "  {$row['file']}:{$row['line']} => {$row['target']}  ({$row['detail']})" . PHP_EOL
                    : "{$row['file']}:{$row['line']} => {$row['target']}" . PHP_EOL;
            }
            $output .= PHP_EOL;
        }
        $output .= "unresolved_include_require=" . count($result['unresolved']) . PHP_EOL;
        foreach ($result['unresolved'] as $row) {
            $output .= "  {$row['file']}:{$row['line']} [{$row['reason']}] {$row['expr']}" . PHP_EOL;
        }

        return $output;
    }

    /**
     * Formats the analysis result as JSON.
     *
     * Machine-readable counterpart of {@see formatSummary()}: like the text
     * form it derives the actionable sections from
     * {@see Analyzer::ACTIONABLE_CATEGORIES} (so a category added there
     * appears in both outputs), appends the informational `unresolved`, and
     * deliberately omits `edges`. Each entry keeps the internal shape
     * verbatim — redundant rows have no `detail`, conflicting rows do.
     *
     * @param AnalysisResult $result
     */
    public function formatSummaryJson(array $result): string
    {
        $payload = [];
        foreach (Analyzer::ACTIONABLE_CATEGORIES as $category) {
            $payload[$category] = $result[$category];
        }
        $payload['unresolved'] = $result['unresolved'];

        return $this->encode($payload);
    }

    /**
     * Formats the result of a `--fix` run as JSON — the machine-readable
     * counterpart of {@see formatFixReport()}, carrying the same `removed`
     * and `skipped` rows.
     *
     * @param FixReport $report
     */
    public function formatFixReportJson(array $report): string
    {
        return $this->encode($report);
    }

    /**
     * Formats reverse-trace output as JSON.
     *
     * @param TraceResult $trace
     */
    public function formatReverseTraceJson(array $trace): string
    {
        return $this->encode($trace);
    }

    /**
     * Encodes a payload as pretty-printed JSON with a trailing newline. Slashes
     * and unicode are left unescaped so paths stay readable (src/Foo.php, not
     * src\/Foo.php).
     *
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        // JSON_INVALID_UTF8_SUBSTITUTE: `expr`/`detail` strings carry raw
        // bytes from the analyzed sources, and legacy files are not always
        // UTF-8. Substituting U+FFFD keeps the report intact instead of
        // json_encode() failing and silently losing every finding.
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return ($json !== false ? $json : '{}') . PHP_EOL;
    }

    /**
     * Formats reverse-trace output.
     *
     * @param TraceResult $trace
     */
    public function formatReverseTrace(array $trace): string
    {
        $output = "trace_target={$trace['target']}" . PHP_EOL;
        $output .= "direct_callers=" . count($trace['directCallers']) . PHP_EOL;
        $output .= $this->formatList($trace['directCallers']);
        $output .= "entrypoint_candidates=" . count($trace['entrypoints']) . PHP_EOL;
        $output .= $this->formatList($trace['entrypoints']);
        $output .= "trace_paths=" . count($trace['paths']) . PHP_EOL;
        $output .= $this->formatPaths($trace['paths']);
        if ($trace['truncated']) {
            $output .= "  (trace path list truncated)" . PHP_EOL;
        }

        return $output;
    }

    /**
     * @param list<string> $items
     */
    private function formatList(array $items): string
    {
        $output = '';
        foreach ($items as $item) {
            $output .= "  - {$item}" . PHP_EOL;
        }

        return $output;
    }

    /**
     * Formats a list of paths.
     *
     * @param list<TracePath> $paths
     */
    private function formatPaths(array $paths): string
    {
        $output = '';
        foreach ($paths as $index => $path) {
            $number = $index + 1;
            $output .= "  {$number}. " . $this->formatSinglePath($path) . PHP_EOL;
        }

        return $output;
    }

    /**
     * Formats a single path.
     * All edges are require/include statements (require_once/require/include_once/include),
     * so the arrow marker is always -[r]->.
     *
     * @param TracePath $path
     */
    private function formatSinglePath(array $path): string
    {
        return implode(' -[r]-> ', $path);
    }
}
