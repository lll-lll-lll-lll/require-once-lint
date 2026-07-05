<?php

declare(strict_types=1);

namespace Depone\Internal\Core;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Depone\Internal\Exception\AnalyzerException;
use Depone\Internal\Tokenizer\IncludeExprParser;
use Depone\Internal\Tokenizer\PathHelper;
use Depone\Internal\Tokenizer\Token;
use Depone\Internal\Tokenizer\TokenHelper;
use SplFileInfo;

/**
 * Analyzes PHP files to detect redundant require_once statements.
 *
 * @phpstan-type RedundantEntry array{file: string, line: int, target: string}
 * @phpstan-type UnresolvedEntry array{file: string, line: int, type: string, reason: string, expr: string}
 * @phpstan-type Edge array{from: string, line: int, type: string, to: string}
 * @phpstan-type AnalysisResult array{redundant: list<RedundantEntry>, unresolved: list<UnresolvedEntry>, edges: list<Edge>}
 *
 * @internal
 */
final class Analyzer
{
    private string $repoRoot;
    private IncludeExprParser $includeExprParser;

    public function __construct(string $repoRoot)
    {
        $this->repoRoot = PathHelper::normalize($repoRoot);
        $this->includeExprParser = new IncludeExprParser();
    }

    /**
     * Runs the analysis and returns redundant require_once statements, unresolved include/require statements, and dependency edges.
     *
     * @return AnalysisResult
     * @throws AnalyzerException
     */
    public function run(): array
    {
        $autoloadedFiles = $this->collectAutoloadedFiles();
        $phpFiles = $this->collectPhpFiles();

        $redundant = [];
        $edges = [];
        $unresolved = [];

        foreach ($phpFiles as $relativeFile) {
            $file = PathHelper::normalize($this->repoRoot . '/' . $relativeFile);
            $content = file_get_contents($file);
            if (!is_string($content)) {
                throw new AnalyzerException("Failed to read file: {$file}");
            }

            $fileResult = $this->analyzeFile($content, $file, $relativeFile, $autoloadedFiles);
            $redundant = array_merge($redundant, $fileResult['redundant']);
            $edges = array_merge($edges, $fileResult['edges']);
            $unresolved = array_merge($unresolved, $fileResult['unresolved']);
        }

        usort($redundant, static function (array $a, array $b): int {
            return [$a['file'], $a['line'], $a['target']] <=> [$b['file'], $b['line'], $b['target']];
        });

        return [
            'redundant' => $redundant,
            'unresolved' => $unresolved,
            'edges' => $edges,
        ];
    }

    /**
     * Analyzes a single file.
     *
     * @param array<string, true> $autoloadedFiles
     * @return AnalysisResult
     */
    private function analyzeFile(
        string $content,
        string $absolutePath,
        string $relativePath,
        array $autoloadedFiles
    ): array {
        $redundant = [];
        $edges = [];
        $unresolved = [];
        $consts = [];

        $tokens = Token::tokenize($content);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = $token->id;
            $text = $token->text;

            // Collect constants defined via define().
            if ($id === T_STRING && strtolower($text) === 'define') {
                $constDefinition = $this->includeExprParser->parseDefine($tokens, $i, $consts, $absolutePath);
                if ($constDefinition !== null) {
                    [$constName, $constValue] = $constDefinition;
                    $consts[$constName] = $constValue;
                }
            }

            // Process require/include statements.
            if (!in_array($id, [T_REQUIRE_ONCE, T_REQUIRE, T_INCLUDE_ONCE, T_INCLUDE], true)) {
                continue;
            }

            $requireType = strtolower(trim($text));
            $line = $token->line;
            $exprTokens = $this->includeExprParser->readIncludeExprTokens($tokens, $i);
            $raw = $this->includeExprParser->evalStaticExpr($exprTokens, $consts, $absolutePath);

            if ($raw === null) {
                $unresolved[] = [
                    'file' => $relativePath,
                    'line' => $line,
                    'type' => $requireType,
                    'reason' => TokenHelper::classifyUnresolvableReason($exprTokens),
                    'expr' => TokenHelper::tokensToString($exprTokens),
                ];
                continue;
            }

            $targetAbs = PathHelper::resolveRequiredPath($raw, $absolutePath);
            $targetRelative = PathHelper::toRelative($targetAbs, $this->repoRoot);
            $edges[] = [
                'from' => $relativePath,
                'line' => $line,
                'type' => $requireType,
                'to' => $targetRelative,
            ];

            if ($requireType === 'require_once' && isset($autoloadedFiles[$targetAbs])) {
                $redundant[] = [
                    'file' => $relativePath,
                    'line' => $line,
                    'target' => $targetRelative,
                ];
            }
        }

        return [
            'redundant' => $redundant,
            'edges' => $edges,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * Collects PHP files registered in the autoload/autoload-dev sections of composer.json.
     * Covers classmap, files, psr-4, and psr-0 entries.
     *
     * @return array<string, true> Associative array keyed by absolute file path
     * @throws AnalyzerException
     */
    private function collectAutoloadedFiles(): array
    {
        $roundTrip = new AutoloadRoundTrip($this->repoRoot);
        $result = $roundTrip->collect();

        $files = $result['eager'];
        foreach ($result['candidates'] as $candidate) {
            foreach ($candidate['classes'] as $class) {
                $resolved = $class['verbose']['resolved'];
                if ($resolved !== null && PathHelper::normalize($resolved) === $candidate['file']) {
                    $files[$candidate['file']] = true;
                    break;
                }
            }
        }

        return $files;
    }

    /**
     * Collects analyzable PHP files in the repository, excluding vendor and .git.
     *
     * @return array<string> Relative paths from the repository root
     */
    private function collectPhpFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($this->repoRoot, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $info): bool {
                    $name = $info->getFilename();
                    if ($info->isDir()) {
                        return !in_array($info->getFilename(), ['.git', 'vendor'], true);
                    }

                    return strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'php';
                }
            )
        );

        foreach ($iterator as $info) {
            assert($info instanceof SplFileInfo);
            $files[] = PathHelper::normalize($info->getPathname());
        }

        sort($files);

        return array_map(fn (string $path): string => PathHelper::toRelative($path, $this->repoRoot), $files);
    }
}
