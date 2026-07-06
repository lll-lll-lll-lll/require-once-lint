<?php

declare(strict_types=1);

namespace Depone\Internal\Core;

/**
 * Formats analysis output.
 *
 * @phpstan-import-type AnalysisResult from \Depone\Internal\Core\Analyzer
 * @phpstan-import-type RedundantProof from \Depone\Internal\Core\Analyzer
 * @phpstan-import-type TraceResult from \Depone\Internal\Core\DependencyGraph
 * @phpstan-import-type TracePath from \Depone\Internal\Core\DependencyGraph
 * @phpstan-import-type VerifyFailure from \Depone\Internal\Resolver\ComposerLoaderVerifier
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
     * Formats a human-facing text summary with a coverage header and
     * autoload evidence under each redundant finding. Unlike formatSummary(),
     * this output is NOT a frozen contract and may change shape between
     * releases; it exists to explain a finding, not to be parsed.
     *
     * Deliberately generates the same section lines formatSummary() does
     * (rather than sharing its implementation) so that method's body can stay
     * untouched and its byte-exact contract stays trivially verifiable.
     *
     * @param AnalysisResult $result
     */
    public function formatSummaryWithEvidence(array $result): string
    {
        $output = "includes_total=" . (count($result['edges']) + count($result['unresolved'])) . PHP_EOL;
        $output .= "resolved=" . count($result['edges']) . PHP_EOL;
        $output .= "unresolved=" . count($result['unresolved']) . PHP_EOL;
        $output .= "needed_require_once=" . count($result['needed']) . PHP_EOL;
        $output .= PHP_EOL;

        foreach (Analyzer::ACTIONABLE_CATEGORIES as $category) {
            $output .= "{$category}_require_once=" . count($result[$category]) . PHP_EOL;
            foreach ($result[$category] as $row) {
                $output .= isset($row['detail'])
                    ? "  {$row['file']}:{$row['line']} => {$row['target']}  ({$row['detail']})" . PHP_EOL
                    : "{$row['file']}:{$row['line']} => {$row['target']}" . PHP_EOL;

                if (isset($row['proof'])) {
                    $output .= $this->formatRedundantEvidence($row['proof']);
                }
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
     * Formats the autoload evidence lines printed under a redundant finding
     * in --explain output.
     *
     * @param RedundantProof $proof
     */
    private function formatRedundantEvidence(array $proof): string
    {
        if ($proof['eager']) {
            return '    autoload.files entry — loaded eagerly on Composer init, so this require is a no-op' . PHP_EOL;
        }

        $output = '';
        foreach ($proof['classes'] as $evidence) {
            $output .= "    {$evidence['class']} => autoloaded via {$evidence['via']} from {$evidence['path']}" . PHP_EOL;
        }
        $output .= '    pure declaration file: autoload reproduces everything this file provides' . PHP_EOL;

        return $output;
    }

    /**
     * Formats the autoload-map cross-check failures gathered by --verify, as
     * a trailing text section. Additive to formatSummary()/
     * formatSummaryWithEvidence() output, so it never disturbs the frozen
     * contract; printed whenever --verify was given, including the
     * `verify_mismatches=0` line when everything verified.
     *
     * @param list<VerifyFailure> $failures
     */
    public function formatVerifySection(array $failures): string
    {
        $output = 'verify_mismatches=' . count($failures) . PHP_EOL;
        foreach ($failures as $failure) {
            $output .= "  {$failure['file']}:{$failure['line']} => {$failure['target']}  ({$this->formatVerifyFailureMessage($failure)})" . PHP_EOL;
        }

        return $output;
    }

    /**
     * @param VerifyFailure $failure
     */
    private function formatVerifyFailureMessage(array $failure): string
    {
        if ($failure['loader_path'] !== null) {
            return "{$failure['class']}: composer loader resolves {$failure['loader_path']}";
        }

        if ($failure['class'] !== null) {
            return "{$failure['class']}: {$failure['reason']}";
        }

        return $failure['reason'];
    }

    /**
     * Formats the analysis result as JSON: depone's machine-readable
     * contract. Every section is built explicitly, key by key, rather than
     * `json_encode`d straight from the internal result array, so the schema
     * is decoupled from internal array shapes and can evolve independently.
     * `schema_version` is bumped whenever the shape changes in a
     * backward-incompatible way.
     *
     * @param AnalysisResult $result
     * @param list<VerifyFailure>|null $mismatches Present only when --verify
     *     was given: null means --verify was not given; [] means verify ran
     *     clean. Drives the top-level `verify` block; the per-redundant
     *     `verified` flag itself comes from the row (annotated by
     *     ComposerLoaderVerifier::verifyFindings), not from this list.
     */
    public function formatJson(array $result, ?array $mismatches = null): string
    {
        $document = [
            'schema_version' => 1,
            'summary' => [
                'includes_total' => count($result['edges']) + count($result['unresolved']),
                'resolved' => count($result['edges']),
                'unresolved' => count($result['unresolved']),
                'require_once' => [
                    'redundant' => count($result['redundant']),
                    'fixable' => count($result['fixable']),
                    'conflicting' => count($result['conflicting']),
                    'needed' => count($result['needed']),
                ],
            ],
            'redundant' => array_map(
                static fn (array $row): array => [
                    'file' => $row['file'],
                    'line' => $row['line'],
                    'target' => $row['target'],
                    'proof' => [
                        'eager' => $row['proof']['eager'],
                        'pure_declaration' => $row['proof']['pure_declaration'],
                        'classes' => array_map(
                            static fn (array $evidence): array => [
                                'class' => $evidence['class'],
                                'via' => $evidence['via'],
                                'prefix' => $evidence['prefix'],
                                'path' => $evidence['path'],
                            ],
                            $row['proof']['classes']
                        ),
                    ],
                    ...array_key_exists('verified', $row) ? ['verified' => $row['verified']] : [],
                ],
                $result['redundant']
            ),
            'fixable' => array_map(
                static fn (array $row): array => [
                    'file' => $row['file'],
                    'line' => $row['line'],
                    'target' => $row['target'],
                    'class' => $row['class'],
                    'expected_path' => $row['expected_path'],
                    'detail' => $row['detail'],
                ],
                $result['fixable']
            ),
            'conflicting' => array_map(
                static fn (array $row): array => [
                    'file' => $row['file'],
                    'line' => $row['line'],
                    'target' => $row['target'],
                    'class' => $row['class'],
                    'loaded_from' => $row['loaded_from'],
                    'detail' => $row['detail'],
                ],
                $result['conflicting']
            ),
            'needed' => array_map(
                static fn (array $row): array => [
                    'file' => $row['file'],
                    'line' => $row['line'],
                    'target' => $row['target'],
                    'reason' => $row['reason'],
                ],
                $result['needed']
            ),
            'unresolved' => array_map(
                static fn (array $row): array => [
                    'file' => $row['file'],
                    'line' => $row['line'],
                    'type' => $row['type'],
                    'reason' => $row['reason'],
                    'expr' => $row['expr'],
                ],
                $result['unresolved']
            ),
            ...$mismatches !== null ? [
                'verify' => [
                    'checked' => count($result['redundant']),
                    'verified' => count(array_filter(array_column($result['redundant'], 'verified'))),
                    'mismatches' => array_map(
                        static fn (array $failure): array => [
                            'file' => $failure['file'],
                            'line' => $failure['line'],
                            'target' => $failure['target'],
                            'class' => $failure['class'],
                            'loader_path' => $failure['loader_path'],
                            'reason' => $failure['reason'],
                        ],
                        $mismatches
                    ),
                ],
            ] : [],
        ];

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('failed to encode analysis result as JSON: ' . json_last_error_msg());
        }

        return $json . PHP_EOL;
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
