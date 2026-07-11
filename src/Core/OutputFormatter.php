<?php

declare(strict_types=1);

namespace Depone\Internal\Core;

/**
 * Formats analysis output.
 *
 * @phpstan-import-type AnalysisResult from \Depone\Internal\Core\Analyzer
 * @phpstan-import-type TraceResult from \Depone\Internal\Core\DependencyGraph
 * @phpstan-import-type TracePath from \Depone\Internal\Core\DependencyGraph
 *
 * @internal
 */
final class OutputFormatter
{
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
     * Machine-readable counterpart of {@see formatSummary()}: it carries the
     * same sections the text summary prints (redundant, conflicting,
     * unresolved) and, like the text form, deliberately omits the informational
     * `edges`. Each entry keeps the internal shape verbatim — redundant rows
     * have no `detail`, conflicting rows do.
     *
     * @param AnalysisResult $result
     */
    public function formatSummaryJson(array $result): string
    {
        return $this->encode([
            'redundant' => $result['redundant'],
            'conflicting' => $result['conflicting'],
            'unresolved' => $result['unresolved'],
        ]);
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
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

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
