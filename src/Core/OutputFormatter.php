<?php

declare(strict_types=1);

namespace Depone\Internal\Core;

/**
 * Formats analysis output.
 *
 * @phpstan-import-type AnalysisResult from \Depone\Internal\Core\Analyzer
 * @phpstan-import-type TraceResult from \Depone\Internal\Core\DependencyGraph
 * @phpstan-import-type TracePath from \Depone\Internal\Core\DependencyGraph
 * @phpstan-import-type DoctorFinding from \Depone\Internal\Core\AutoloadDoctor
 * @phpstan-import-type DoctorResult from \Depone\Internal\Core\AutoloadDoctor
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
     * Formats doctor diagnosis output.
     *
     * Sections (errors, warnings, info) are printed in that order, each with a
     * `key=count` header followed by indented `file: detail` lines. Only sections
     * at or above `$minSeverity` are printed; the default reports errors alone,
     * since warnings and info are frequently fixture-driven noise. Widen with
     * `--min-severity=warning` (adds warnings) or `--min-severity=info` (adds all).
     *
     * @param DoctorResult $result
     * @param 'error'|'warning'|'info' $minSeverity Lowest severity section to include.
     */
    public function formatDoctor(array $result, string $minSeverity = 'error'): string
    {
        $sections = [];
        $sections[] = $this->formatDoctorSection('autoload_unreachable_errors', $result['errors']);
        if ($minSeverity === 'warning' || $minSeverity === 'info') {
            $sections[] = $this->formatDoctorSection('autoload_unreachable_warnings', $result['warnings']);
        }
        if ($minSeverity === 'info') {
            $sections[] = $this->formatDoctorSection('autoload_unreachable_info', $result['info']);
        }

        return implode(PHP_EOL, $sections);
    }

    /**
     * @param list<DoctorFinding> $findings
     */
    private function formatDoctorSection(string $label, array $findings): string
    {
        $output = "{$label}=" . count($findings) . PHP_EOL;
        foreach ($findings as $finding) {
            $output .= "  {$finding['file']}: {$finding['detail']}" . PHP_EOL;
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
