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
 * Analyzes PHP files to detect redundant require_once statements and classify
 * every require_once by its relationship to Composer autoload.
 *
 * A require_once is `redundant` (deletable outright) only when deleting it
 * provably changes nothing: the target is an `autoload.files` entry (loaded
 * eagerly before any code runs), or *every* class the target declares
 * autoloads back to the target itself. Otherwise the declared classes decide:
 * a class autoload resolves to a *different* file makes the require `conflicting`
 * (it loads a shadowed copy — a hazard, not a simple delete); a class whose PSR
 * rule matches but whose derived path is missing makes the require `fixable`
 * (fix the autoload config, then the require can be dropped). Requires that are
 * legitimately not autoloadable (no matching rule, or the target declares no
 * types) are left unreported.
 *
 * @phpstan-type RedundantEntry array{file: string, line: int, target: string}
 * @phpstan-type ClassifiedEntry array{file: string, line: int, target: string, detail: string}
 * @phpstan-type UnresolvedEntry array{file: string, line: int, type: string, reason: string, expr: string}
 * @phpstan-type Edge array{from: string, line: int, type: string, to: string}
 * @phpstan-type AnalysisResult array{redundant: list<RedundantEntry>, fixable: list<ClassifiedEntry>, conflicting: list<ClassifiedEntry>, unresolved: list<UnresolvedEntry>, edges: list<Edge>}
 *
 * @internal
 */
final class Analyzer
{
    /**
     * The result categories that are actionable findings: any entry in one of
     * them means the codebase has work to do. The CLI exit-code gate and the
     * summary sections iterate this list — a category added to {@see run()}
     * but not listed here would be invisible to both, under-reporting while
     * CI stays green. `unresolved` and `edges` are informational only and
     * deliberately excluded.
     */
    public const ACTIONABLE_CATEGORIES = ['redundant', 'fixable', 'conflicting'];

    private string $repoRoot;
    private IncludeExprParser $includeExprParser;

    /**
     * Per-target memo of require_once classifications: legacy code commonly
     * requires the same file from many places, and re-tokenizing the target
     * for every edge would be wasted work.
     *
     * @var array<string, array{category: 'redundant', detail: null}|array{category: 'fixable'|'conflicting', detail: string}|null>
     */
    private array $requireClassifications = [];

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
        $resolver = new AutoloadResolver($this->repoRoot);
        $classExtractor = new DeclaredClassExtractor();

        $eagerFiles = $this->collectEagerFiles();
        $phpFiles = $this->collectPhpFiles();

        $redundant = [];
        $fixable = [];
        $conflicting = [];
        $edges = [];
        $unresolved = [];

        foreach ($phpFiles as $relativeFile) {
            $file = PathHelper::normalize($this->repoRoot . '/' . $relativeFile);
            $content = file_get_contents($file);
            if (!is_string($content)) {
                throw new AnalyzerException("Failed to read file: {$file}");
            }

            $fileResult = $this->analyzeFile($content, $file, $relativeFile, $eagerFiles, $resolver, $classExtractor);
            $redundant = array_merge($redundant, $fileResult['redundant']);
            $fixable = array_merge($fixable, $fileResult['fixable']);
            $conflicting = array_merge($conflicting, $fileResult['conflicting']);
            $edges = array_merge($edges, $fileResult['edges']);
            $unresolved = array_merge($unresolved, $fileResult['unresolved']);
        }

        $sortByLocation = static function (array $a, array $b): int {
            return [$a['file'], $a['line'], $a['target']] <=> [$b['file'], $b['line'], $b['target']];
        };
        usort($redundant, $sortByLocation);
        usort($fixable, $sortByLocation);
        usort($conflicting, $sortByLocation);

        return [
            'redundant' => $redundant,
            'fixable' => $fixable,
            'conflicting' => $conflicting,
            'unresolved' => $unresolved,
            'edges' => $edges,
        ];
    }

    /**
     * Analyzes a single file.
     *
     * @param array<string, true> $eagerFiles `autoload.files` entries, loaded eagerly by Composer
     * @return AnalysisResult
     */
    private function analyzeFile(
        string $content,
        string $absolutePath,
        string $relativePath,
        array $eagerFiles,
        AutoloadResolver $resolver,
        DeclaredClassExtractor $classExtractor
    ): array {
        $redundant = [];
        $fixable = [];
        $conflicting = [];
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

            if ($requireType !== 'require_once') {
                continue;
            }

            // Eager `autoload.files` entries load on Composer init, before
            // any code runs, so this require is a no-op no matter what the
            // target declares.
            $classification = isset($eagerFiles[$targetAbs])
                ? ['category' => 'redundant', 'detail' => null]
                : $this->classifyRequireTarget($targetAbs, $resolver, $classExtractor);

            if ($classification === null) {
                // "needed": the target is legitimately not autoloadable.
                continue;
            }

            $entry = [
                'file' => $relativePath,
                'line' => $line,
                'target' => $targetRelative,
            ];

            if ($classification['category'] === 'redundant') {
                $redundant[] = $entry;
                continue;
            }

            $entry['detail'] = $classification['detail'];
            if ($classification['category'] === 'conflicting') {
                $conflicting[] = $entry;
            } else {
                $fixable[] = $entry;
            }
        }

        return [
            'redundant' => $redundant,
            'fixable' => $fixable,
            'conflicting' => $conflicting,
            'edges' => $edges,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * Classifies a require_once by the autoload reachability of its target.
     *
     * - `redundant`: *every* declared class autoloads back to the target, so
     *   deleting the require cannot change where any declaration loads from.
     *   One round-tripping class is not enough: a sibling class that resolves
     *   elsewhere (or nowhere) makes the require load-bearing.
     * - `conflicting`: some declared class is autoloaded from a *different*
     *   file. Deleting the require would silently swap which definition loads,
     *   so this hazard dominates every other category.
     * - `fixable`: no conflicts, but some class matches a PSR rule whose
     *   derived path does not exist. Fix the autoload config, then the require
     *   can be dropped.
     * - null ("needed"): the target declares no types, or a class no autoload
     *   rule covers. The require is legitimate and stays unreported.
     *
     * @return array{category: 'redundant', detail: null}|array{category: 'fixable'|'conflicting', detail: string}|null
     */
    private function classifyRequireTarget(
        string $targetAbs,
        AutoloadResolver $resolver,
        DeclaredClassExtractor $classExtractor
    ): ?array {
        $normalizedTarget = PathHelper::normalize($targetAbs);
        if (array_key_exists($normalizedTarget, $this->requireClassifications)) {
            return $this->requireClassifications[$normalizedTarget];
        }

        return $this->requireClassifications[$normalizedTarget]
            = $this->computeRequireClassification($normalizedTarget, $resolver, $classExtractor);
    }

    /**
     * @return array{category: 'redundant', detail: null}|array{category: 'fixable'|'conflicting', detail: string}|null
     */
    private function computeRequireClassification(
        string $normalizedTarget,
        AutoloadResolver $resolver,
        DeclaredClassExtractor $classExtractor
    ): ?array {
        // Require targets are arbitrary paths, including ones that point nowhere.
        if (!is_file($normalizedTarget)) {
            return null;
        }
        $content = file_get_contents($normalizedTarget);
        if (!is_string($content)) {
            return null;
        }

        $classNames = $classExtractor->extract($content);
        if ($classNames === []) {
            return null;
        }

        $allRoundTrip = true;
        $fixable = null;

        foreach ($classNames as $className) {
            $resolution = $resolver->resolveVerbose($className);
            $resolved = $resolution['resolved'];

            if ($resolved !== null && PathHelper::normalize($resolved) !== $normalizedTarget) {
                // A shadowed copy is a hazard however the file is shaped, so
                // conflicting is reported even for files with side effects.
                $winner = PathHelper::toRelative(PathHelper::normalize($resolved), $this->repoRoot);

                return [
                    'category' => 'conflicting',
                    'detail' => "{$className} is autoloaded from {$winner} — this require loads a shadowed copy",
                ];
            }

            if ($resolved !== null) {
                // Round-trips to the target.
                continue;
            }

            $allRoundTrip = false;

            if ($resolution['expectedPath'] !== null && $fixable === null) {
                $expected = PathHelper::toRelative(PathHelper::normalize($resolution['expectedPath']), $this->repoRoot);
                $fixable = [
                    'category' => 'fixable',
                    'detail' => "{$className} would load from {$expected} — fix autoload, then remove this require",
                ];
            }
        }

        // Redundant/fixable both promise the require can eventually go, which
        // only holds if autoload reproduces everything the target provides.
        // Autoload loads class-like declarations lazily and nothing else, so a
        // target that also defines functions/constants or runs top-level side
        // effects keeps the require load-bearing: leave it unreported.
        if (!$classExtractor->declaresOnlyTypes($content)) {
            return null;
        }

        if ($fixable !== null) {
            return $fixable;
        }

        return $allRoundTrip ? ['category' => 'redundant', 'detail' => null] : null;
    }

    /**
     * Collects the `files` entries of the autoload/autoload-dev sections.
     * Composer loads these eagerly on initialization, so a require_once whose
     * target is one of them is redundant regardless of what the file declares.
     *
     * @return array<string, true> Associative array keyed by absolute file path
     * @throws AnalyzerException
     */
    private function collectEagerFiles(): array
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

        foreach (['autoload', 'autoload-dev'] as $sectionName) {
            $autoload = $composer[$sectionName] ?? [];
            if (!is_array($autoload)) {
                continue;
            }

            $entries = $autoload['files'] ?? [];
            if (!is_array($entries)) {
                continue;
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
