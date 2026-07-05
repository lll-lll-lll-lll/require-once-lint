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
        $output = "redundant_require_once=" . count($result['redundant']) . PHP_EOL;
        foreach ($result['redundant'] as $row) {
            $output .= "{$row['file']}:{$row['line']} => {$row['target']}" . PHP_EOL;
        }
        $output .= PHP_EOL;
        $output .= "fixable_require_once=" . count($result['fixable']) . PHP_EOL;
        foreach ($result['fixable'] as $row) {
            $output .= "  {$row['file']}:{$row['line']} => {$row['target']}  ({$row['detail']})" . PHP_EOL;
        }
        $output .= PHP_EOL;
        $output .= "conflicting_require_once=" . count($result['conflicting']) . PHP_EOL;
        foreach ($result['conflicting'] as $row) {
            $output .= "  {$row['file']}:{$row['line']} => {$row['target']}  ({$row['detail']})" . PHP_EOL;
        }
        $output .= PHP_EOL;
        $output .= "unresolved_include_require=" . count($result['unresolved']) . PHP_EOL;
        foreach ($result['unresolved'] as $row) {
            $output .= "  {$row['file']}:{$row['line']} [{$row['reason']}] {$row['expr']}" . PHP_EOL;
        }

        return $output;
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
