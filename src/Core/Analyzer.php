<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Core;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RedundantRequireOnce\Exception\AnalyzerException;
use RedundantRequireOnce\Resolver\AutoloadResolver;
use RedundantRequireOnce\Resolver\ClassReferenceDetector;
use RedundantRequireOnce\Tokenizer\IncludeExprParser;
use RedundantRequireOnce\Tokenizer\PathHelper;
use RedundantRequireOnce\Tokenizer\Token;
use RedundantRequireOnce\Tokenizer\TokenHelper;
use SplFileInfo;

/**
 * Analyzes PHP files to detect redundant require_once statements.
 */
final class Analyzer
{
    private string $repoRoot;
    private IncludeExprParser $includeExprParser;
    private ?AutoloadResolver $autoloadResolver = null;
    private ?ClassReferenceDetector $classReferenceDetector = null;
    private bool $includeAutoloadEdges = false;
    /** @var array<string, string> */
    private array $globalConsts = [];

    public function __construct(string $repoRoot, ?IncludeExprParser $includeExprParser = null)
    {
        $this->repoRoot = PathHelper::normalize($repoRoot);
        $this->includeExprParser = $includeExprParser ?? new IncludeExprParser();
    }

    /**
     * Sets the global constants to use during analysis.
     *
     * @param array<string, string> $consts Map of constant name => value
     */
    public function withGlobalConsts(array $consts): self
    {
        $this->globalConsts = $consts;
        return $this;
    }

    /**
     * Enables collection of autoload edges.
     */
    public function enableAutoloadEdges(): self
    {
        $this->includeAutoloadEdges = true;
        $this->autoloadResolver = new AutoloadResolver($this->repoRoot);
        $this->classReferenceDetector = new ClassReferenceDetector();
        return $this;
    }

    /**
     * Runs the analysis and returns redundant require_once statements, unresolved include/require statements, and dependency edges.
     *
     * @return array{redundant: array, nonAutoloadRequireOnce: array, unresolved: array, edges: array}
     * @throws AnalyzerException
     */
    public function run(): array
    {
        $autoloadedFiles = $this->collectAutoloadedFiles();
        $phpFiles = $this->collectPhpFiles();

        $globalConsts = $this->globalConsts;

        $redundant = [];
        $nonAutoloadRequireOnce = [];
        $edges = [];
        $unresolved = [];

        foreach ($phpFiles as $relativeFile) {
            $file = PathHelper::normalize($this->repoRoot . '/' . $relativeFile);
            $content = file_get_contents($file);
            if (!is_string($content)) {
                throw new AnalyzerException("Failed to read file: {$file}");
            }

            $fileResult = $this->analyzeFile($content, $file, $relativeFile, $globalConsts, $autoloadedFiles);
            $redundant = array_merge($redundant, $fileResult['redundant']);
            $nonAutoloadRequireOnce = array_merge($nonAutoloadRequireOnce, $fileResult['nonAutoloadRequireOnce']);
            $edges = array_merge($edges, $fileResult['edges']);
            $unresolved = array_merge($unresolved, $fileResult['unresolved']);
        }

        usort($redundant, static function (array $a, array $b): int {
            return [$a['file'], $a['line'], $a['target']] <=> [$b['file'], $b['line'], $b['target']];
        });
        usort($nonAutoloadRequireOnce, static function (array $a, array $b): int {
            return [$a['file'], $a['line'], $a['target']] <=> [$b['file'], $b['line'], $b['target']];
        });

        return [
            'redundant' => $redundant,
            'nonAutoloadRequireOnce' => $nonAutoloadRequireOnce,
            'unresolved' => $unresolved,
            'edges' => $edges,
        ];
    }

    /**
     * Analyzes a single file.
     */
    public function analyzeFile(
        string $content,
        string $absolutePath,
        string $relativePath,
        array $globalConsts,
        array $autoloadedFiles
    ): array {
        $redundant = [];
        $nonAutoloadRequireOnce = [];
        $edges = [];
        $unresolved = [];
        $consts = $globalConsts;

        $tokens = Token::tokenize($content);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = TokenHelper::id($token);
            $text = TokenHelper::text($token);

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
            // Skip repository-relative conversion for URLs.
            $targetRelative = PathHelper::isUrl($targetAbs)
                ? $targetAbs
                : PathHelper::toRelative($targetAbs, $this->repoRoot);
            $edges[] = [
                'from' => $relativePath,
                'line' => $line,
                'type' => $requireType,
                'to' => $targetRelative,
            ];

            if ($requireType === 'require_once') {
                $row = [
                    'file' => $relativePath,
                    'line' => $line,
                    'target' => $targetRelative,
                ];

                if (isset($autoloadedFiles[$targetAbs])) {
                    $redundant[] = $row;
                } else {
                    $nonAutoloadRequireOnce[] = $row;
                }
            }
        }

        // Collect autoload edges.
        if ($this->includeAutoloadEdges && $this->classReferenceDetector !== null && $this->autoloadResolver !== null) {
            $autoloadEdges = $this->collectAutoloadEdges($content, $relativePath);
            $edges = array_merge($edges, $autoloadEdges);
        }

        return [
            'redundant' => $redundant,
            'nonAutoloadRequireOnce' => $nonAutoloadRequireOnce,
            'edges' => $edges,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * Collects autoload edges from class references within a file.
     *
     * @return array<array{from: string, line: int, type: string, to: string, class: string}>
     */
    private function collectAutoloadEdges(string $content, string $relativePath): array
    {
        assert($this->classReferenceDetector !== null);
        assert($this->autoloadResolver !== null);

        $edges = [];
        $classNames = $this->classReferenceDetector->detect($content);

        foreach ($classNames as $className) {
            $targetFile = $this->autoloadResolver->resolve($className);
            if ($targetFile === null) {
                continue;
            }

            $targetRelative = PathHelper::toRelative($targetFile, $this->repoRoot);
            // Exclude self-references.
            if ($targetRelative === $relativePath) {
                continue;
            }

            $edges[] = [
                'from' => $relativePath,
                'line' => 0,  // Line numbers for class references are not currently tracked.
                'type' => 'autoload',
                'to' => $targetRelative,
                'class' => $className,
            ];
        }

        return $edges;
    }

    /**
     * Collects PHP files registered in the autoload/autoload-dev sections of composer.json.
     * Covers classmap, files, psr-4, and psr-0 entries.
     *
     * @return array<string, true> Associative array keyed by absolute file path
     * @throws AnalyzerException
     */
    public function collectAutoloadedFiles(): array
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
        foreach (array_keys($candidateFiles) as $filePath) {
            foreach ($this->extractDeclaredClassNames($filePath) as $className) {
                $resolved = $resolver->resolve($className);
                if ($resolved !== null && PathHelper::normalize($resolved) === PathHelper::normalize($filePath)) {
                    $files[PathHelper::normalize($filePath)] = true;
                    break;
                }
            }
        }

        return $files;
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
     * @return array<string>
     */
    private function extractDeclaredClassNames(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if (!is_string($content)) {
            return [];
        }

        $tokens = Token::tokenize($content);
        $tokenCount = count($tokens);
        $namespace = '';
        $classNames = [];
        $enumToken = defined('T_ENUM') ? T_ENUM : -1;

        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];
            $id = TokenHelper::id($token);

            if ($id === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $tokenCount; $j++) {
                    $nameToken = $tokens[$j];
                    if ($nameToken->text === ';' || $nameToken->text === '{') {
                        break;
                    }
                    if (TokenHelper::isNameToken(TokenHelper::id($nameToken))) {
                        $namespace .= TokenHelper::text($nameToken);
                    }
                }
                continue;
            }

            if (!in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, $enumToken], true)) {
                continue;
            }

            if ($id === T_CLASS && $this->isAnonymousClassToken($tokens, $i)) {
                continue;
            }

            for ($j = $i + 1; $j < $tokenCount; $j++) {
                if (TokenHelper::id($tokens[$j]) === T_STRING) {
                    $shortName = TokenHelper::text($tokens[$j]);
                    $classNames[] = $namespace !== '' ? $namespace . '\\' . $shortName : $shortName;
                    break;
                }
            }
        }

        return array_values(array_unique($classNames));
    }

    /**
     * @param list<Token> $tokens
     */
    private function isAnonymousClassToken(array $tokens, int $classIndex): bool
    {
        for ($i = $classIndex - 1; $i >= 0; $i--) {
            $id = TokenHelper::id($tokens[$i]);
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $id === T_NEW;
        }

        return false;
    }

    /**
     * Collects analyzable PHP files in the repository, excluding vendor and .git.
     *
     * @return array<string> Relative paths from the repository root
     */
    public function collectPhpFiles(): array
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
