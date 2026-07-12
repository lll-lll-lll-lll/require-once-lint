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
     * Formats the side-effect inventory: one block per kept require target,
     * with how often it is required, the reasons it is load-bearing, and the
     * inventory of its top-level side effects (kind:line + excerpt).
     *
     * @param AnalysisResult $result
     */
    public function formatInventory(array $result): string
    {
        $output = 'kept_require_once=' . count($result['kept']) . PHP_EOL;
        foreach ($result['kept'] as $entry) {
            $output .= PHP_EOL . $entry['target'] . PHP_EOL;
            $output .= '  required_from=' . count($entry['requiredFrom']) . PHP_EOL;
            $output .= '  reasons=' . implode(',', $entry['reasons']) . PHP_EOL;
            if ($entry['unreachableClasses'] !== []) {
                $output .= '  unreachable_classes=' . implode(',', $entry['unreachableClasses']) . PHP_EOL;
            }
            foreach ($entry['sideEffects'] as $effect) {
                $output .= "  {$effect['kind']}:{$effect['line']}  {$effect['excerpt']}" . PHP_EOL;
            }
        }

        return $output;
    }

    /**
     * Machine-readable counterpart of {@see formatInventory()}. Unlike the
     * text form it also carries each kept target's requiring file:line list.
     *
     * @param AnalysisResult $result
     */
    public function formatInventoryJson(array $result): string
    {
        return $this->encode(['kept' => $result['kept']]);
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
