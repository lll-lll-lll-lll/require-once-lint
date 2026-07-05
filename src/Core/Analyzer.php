<?php

declare(strict_types=1);

namespace Depone\Internal\Core;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Depone\Internal\Exception\AnalyzerException;
use Depone\Internal\Resolver\AutoloadResolver;
use Depone\Internal\Tokenizer\DeclaredClassExtractor;
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
        $composerPath = $this->repoRoot . '/composer.json';
        if (!is_file($composerPath)) {
            throw new AnalyzerException("Failed to read composer.json");
        }
        $json = file_get_contents($composerPath);
        if (!is_string($json)) {
            throw new AnalyzerException("Failed to read composer.json");
        }
        $composer = json_decode($json, true);
        if (!is_array($composer)) {
            throw new AnalyzerException("Failed to decode composer.json");
        }

        $files = [];
        $candidateFiles = [];

        foreach (['autoload', 'autoload-dev'] as $sectionName) {
            $autoload = $composer[$sectionName] ?? [];
            if (!is_array($autoload)) {
                continue;
            }

            // files: individual files
            $this->collectFromFiles($autoload['files'] ?? [], $files);

            // classmap: directories or individual files
            $this->collectFromClassmap($autoload['classmap'] ?? [], $candidateFiles);

            // psr-4: namespace => directory mapping
            $this->collectFromPsr4($autoload['psr-4'] ?? [], $candidateFiles);

            // psr-0: namespace => directory mapping (legacy)
            $this->collectFromPsr4($autoload['psr-0'] ?? [], $candidateFiles);
        }

        $resolver = new AutoloadResolver($this->repoRoot);
        $classExtractor = new DeclaredClassExtractor();
        foreach (array_keys($candidateFiles) as $filePath) {
            if ($this->isRedundantlyRequirable($filePath, $resolver, $classExtractor)) {
                $files[PathHelper::normalize($filePath)] = true;
            }
        }

        return $files;
    }

    /**
     * Reports whether requiring this file is provably redundant with autoload:
     * deleting such a require cannot change what loads or when.
     *
     * That holds only when the file is a pure declaration file (no functions,
     * constants, or top-level side effects — autoload would never reproduce
     * those) AND *every* class it declares autoloads back to this same file. A
     * single class that autoload resolves elsewhere, or that is not reachable at
     * all, means the require is load-bearing and must not be called redundant.
     */
    private function isRedundantlyRequirable(
        string $filePath,
        AutoloadResolver $resolver,
        DeclaredClassExtractor $classExtractor
    ): bool {
        $content = file_get_contents($filePath);
        if (!is_string($content)) {
            return false;
        }

        $classNames = $classExtractor->extract($content);
        if ($classNames === [] || !$classExtractor->declaresOnlyTypes($content)) {
            return false;
        }

        $normalizedFile = PathHelper::normalize($filePath);
        foreach ($classNames as $className) {
            $resolved = $resolver->resolve($className);
            if ($resolved === null || PathHelper::normalize($resolved) !== $normalizedFile) {
                return false;
            }
        }

        return true;
    }

    /**
     * Collects files from classmap entries.
     *
     * @param array<string, true> $files Destination array, passed by reference
     */
    private function collectFromClassmap(mixed $entries, array &$files): void
    {
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $absolute = PathHelper::normalize($this->repoRoot . '/' . ltrim($entry, '/'));
            $this->collectPhpFilesFromPath($absolute, $files);
        }
    }

    /**
     * Collects files from `files` entries.
     *
     * @param array<string, true> $files Destination array, passed by reference
     */
    private function collectFromFiles(mixed $entries, array &$files): void
    {
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $absolute = PathHelper::normalize($this->repoRoot . '/' . ltrim($entry, '/'));
            if (is_file($absolute)) {
                $files[$absolute] = true;
            }
        }
    }

    /**
     * Collects files from psr-4/psr-0 entries.
     *
     * @param array<string, true> $files Destination array, passed by reference
     */
    private function collectFromPsr4(mixed $entries, array &$files): void
    {
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $paths) {
            // Paths may be declared as a string or an array.
            $pathList = is_array($paths) ? $paths : [$paths];
            foreach ($pathList as $path) {
                if (!is_string($path)) {
                    continue;
                }
                $absolute = PathHelper::normalize($this->repoRoot . '/' . ltrim($path, '/'));
                $this->collectPhpFilesFromPath($absolute, $files);
            }
        }
    }

    /**
     * Collects PHP files from the given path, whether it is a file or directory.
     *
     * @param array<string, true> $files Destination array, passed by reference
     */
    private function collectPhpFilesFromPath(string $absolute, array &$files): void
    {
        if (is_dir($absolute)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $info) {
                /** @var SplFileInfo $info */
                if ($info->isFile() && strtolower($info->getExtension()) === 'php') {
                    $files[PathHelper::normalize((string)$info->getPathname())] = true;
                }
            }
            return;
        }

        if (is_file($absolute)) {
            $files[$absolute] = true;
        }
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
