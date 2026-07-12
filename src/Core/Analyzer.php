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
 * (it loads a shadowed copy — a hazard, not a simple delete). Requires that are
 * legitimately not autoloadable (no matching rule, the target declares no
 * types, or some declared class is not autoload-reachable from the target) are
 * left out of the findings, but recorded in the informational `kept` inventory
 * together with the reasons they are load-bearing — the map of why the
 * remaining requires cannot be removed. Targets under vendor/ (in practice
 * vendor/autoload.php) are excluded from the inventory: they are not the
 * user's code to migrate.
 *
 * @phpstan-type RedundantEntry array{file: string, line: int, target: string}
 * @phpstan-type ClassifiedEntry array{file: string, line: int, target: string, detail: string}
 * @phpstan-type UnresolvedEntry array{file: string, line: int, type: string, reason: string, expr: string}
 * @phpstan-type Edge array{from: string, line: int, type: string, to: string}
 * @phpstan-import-type SideEffect from \Depone\Internal\Tokenizer\TargetProfile
 * @phpstan-type KeptEntry array{target: string, requiredFrom: list<array{file: string, line: int}>, reasons: list<string>, sideEffects: list<SideEffect>, unreachableClasses: list<string>}
 * @phpstan-type KeptUsage array{target: string, targetAbs: string, file: string, line: int}
 * @phpstan-type KeptClassification array{category: 'kept', reasons: list<string>, sideEffects: list<SideEffect>, unreachableClasses: list<string>}
 * @phpstan-type Classification array{category: 'redundant', detail: null}|array{category: 'conflicting', detail: string}|KeptClassification
 * @phpstan-type AnalysisResult array{redundant: list<RedundantEntry>, conflicting: list<ClassifiedEntry>, unresolved: list<UnresolvedEntry>, kept: list<KeptEntry>, edges: list<Edge>}
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
     * CI stays green. `unresolved`, `kept`, and `edges` are informational only
     * and deliberately excluded.
     */
    public const ACTIONABLE_CATEGORIES = ['redundant', 'conflicting'];

    private string $repoRoot;
    private IncludeExprParser $includeExprParser;

    /**
     * Per-target memo of require_once classifications: legacy code commonly
     * requires the same file from many places, and re-tokenizing the target
     * for every edge would be wasted work.
     *
     * @var array<string, Classification>
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
        $conflicting = [];
        $edges = [];
        $unresolved = [];
        $keptUsages = [];

        foreach ($phpFiles as $relativeFile) {
            $file = PathHelper::normalize($this->repoRoot . '/' . $relativeFile);
            $content = file_get_contents($file);
            if (!is_string($content)) {
                throw new AnalyzerException("Failed to read file: {$file}");
            }

            $fileResult = $this->analyzeFile($content, $file, $relativeFile, $eagerFiles, $resolver, $classExtractor);
            $redundant = array_merge($redundant, $fileResult['redundant']);
            $conflicting = array_merge($conflicting, $fileResult['conflicting']);
            $edges = array_merge($edges, $fileResult['edges']);
            $unresolved = array_merge($unresolved, $fileResult['unresolved']);
            $keptUsages = array_merge($keptUsages, $fileResult['kept']);
        }

        $sortByLocation = static function (array $a, array $b): int {
            return [$a['file'], $a['line'], $a['target']] <=> [$b['file'], $b['line'], $b['target']];
        };
        usort($redundant, $sortByLocation);
        usort($conflicting, $sortByLocation);

        return [
            'redundant' => $redundant,
            'conflicting' => $conflicting,
            'unresolved' => $unresolved,
            'kept' => $this->aggregateKept($keptUsages),
            'edges' => $edges,
        ];
    }

    /**
     * Groups kept require usages by target file and joins each group with the
     * memoized classification, producing the side-effect inventory: one entry
     * per load-bearing target with the reasons it must stay.
     *
     * @param list<KeptUsage> $usages
     * @return list<KeptEntry>
     */
    private function aggregateKept(array $usages): array
    {
        usort($usages, static function (array $a, array $b): int {
            return [$a['file'], $a['line']] <=> [$b['file'], $b['line']];
        });

        $byTarget = [];
        foreach ($usages as $usage) {
            $target = $usage['target'];
            if (!isset($byTarget[$target])) {
                $classification = $this->requireClassifications[PathHelper::normalize($usage['targetAbs'])];
                if ($classification['category'] !== 'kept') {
                    continue;
                }
                $byTarget[$target] = [
                    'target' => $target,
                    'requiredFrom' => [],
                    'reasons' => $classification['reasons'],
                    'sideEffects' => $classification['sideEffects'],
                    'unreachableClasses' => $classification['unreachableClasses'],
                ];
            }
            $byTarget[$target]['requiredFrom'][] = ['file' => $usage['file'], 'line' => $usage['line']];
        }

        $kept = array_values($byTarget);
        usort($kept, static function (array $a, array $b): int {
            return $a['target'] <=> $b['target'];
        });

        return $kept;
    }

    /**
     * Analyzes a single file.
     *
     * @param array<string, true> $eagerFiles `autoload.files` entries, loaded eagerly by Composer
     * @return array{redundant: list<RedundantEntry>, conflicting: list<ClassifiedEntry>, unresolved: list<UnresolvedEntry>, edges: list<Edge>, kept: list<KeptUsage>}
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
        $conflicting = [];
        $edges = [];
        $unresolved = [];
        $kept = [];
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

            if ($classification['category'] === 'kept') {
                // "needed": the require is load-bearing. It appears in no
                // findings section, but the inventory records why it must
                // stay — except for vendor targets (vendor/autoload.php in
                // practice), which are not the user's code to migrate.
                if (!str_starts_with($targetAbs, $this->repoRoot . '/vendor/')) {
                    $kept[] = [
                        'target' => $targetRelative,
                        'targetAbs' => $targetAbs,
                        'file' => $relativePath,
                        'line' => $line,
                    ];
                }
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

            // The only remaining classification is `conflicting`.
            $entry['detail'] = $classification['detail'];
            $conflicting[] = $entry;
        }

        return [
            'redundant' => $redundant,
            'conflicting' => $conflicting,
            'edges' => $edges,
            'unresolved' => $unresolved,
            'kept' => $kept,
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
     *   so this hazard dominates every other outcome.
     * - `kept` ("needed"): the require is load-bearing and stays out of the
     *   findings; the classification carries the reasons why — the target
     *   declares no types (`no_types`) or only conditional ones
     *   (`guarded_declarations_only`, e.g. a polyfill behind a `class_exists`
     *   guard), a declared class is not autoload-reachable back to the target
     *   (`class_not_autoloadable`, with the class names), the file carries
     *   top-level side effects (`side_effects`, with the inventory), or the
     *   target is missing/unreadable/unparsable.
     *
     * @return Classification
     */
    private function classifyRequireTarget(
        string $targetAbs,
        AutoloadResolver $resolver,
        DeclaredClassExtractor $classExtractor
    ): array {
        $normalizedTarget = PathHelper::normalize($targetAbs);
        if (array_key_exists($normalizedTarget, $this->requireClassifications)) {
            return $this->requireClassifications[$normalizedTarget];
        }

        return $this->requireClassifications[$normalizedTarget]
            = $this->computeRequireClassification($normalizedTarget, $resolver, $classExtractor);
    }

    /**
     * @return Classification
     */
    private function computeRequireClassification(
        string $normalizedTarget,
        AutoloadResolver $resolver,
        DeclaredClassExtractor $classExtractor
    ): array {
        // Require targets are arbitrary paths, including ones that point nowhere.
        if (!is_file($normalizedTarget)) {
            return self::kept(['target_missing']);
        }
        $content = file_get_contents($normalizedTarget);
        if (!is_string($content)) {
            return self::kept(['target_unreadable']);
        }

        $profile = $classExtractor->profile($content);
        if (!$profile->parsed) {
            return self::kept(['unparsable'], $profile->sideEffects);
        }

        // The conflict/round-trip logic below must only trust classes the file
        // declares unconditionally: a class behind an `if (!class_exists())`
        // guard (a polyfill) does not shadow the real one, so it must not make
        // the require conflicting.
        $unreachable = [];

        foreach ($profile->topLevelClasses as $className) {
            $resolved = $resolver->resolve($className);

            if (
                $resolved !== null
                && PathHelper::normalize($resolved) !== $normalizedTarget
                && !$this->sameRealFile($resolved, $normalizedTarget)
            ) {
                // A shadowed copy is a hazard however the file is shaped, so
                // conflicting is reported even for files with side effects.
                $winner = PathHelper::toRelative(PathHelper::normalize($resolved), $this->repoRoot);

                return [
                    'category' => 'conflicting',
                    'detail' => "{$className} is autoloaded from {$winner} — this require loads a shadowed copy",
                ];
            }

            if ($resolved === null) {
                // A class autoload cannot reach back to the target keeps the
                // require load-bearing: deleting it would drop this class.
                $unreachable[] = $className;
            }
        }

        // Redundant promises the require can go, which only holds if autoload
        // reproduces everything the target provides. Autoload loads class-like
        // declarations lazily and nothing else, so every reason below keeps
        // the require load-bearing — collected in full (not first-match) so
        // the kept inventory can report the complete picture.
        $reasons = [];
        if ($profile->topLevelClasses === []) {
            // A polyfill (guarded declarations only) is kept for a different
            // reason than a file that declares no types at all.
            $reasons[] = $profile->declaredClasses === [] ? 'no_types' : 'guarded_declarations_only';
        }
        if ($unreachable !== []) {
            $reasons[] = 'class_not_autoloadable';
        }
        if ($profile->sideEffects !== []) {
            $reasons[] = 'side_effects';
        }

        if ($reasons === []) {
            return ['category' => 'redundant', 'detail' => null];
        }

        return self::kept($reasons, $profile->sideEffects, $unreachable);
    }

    /**
     * @param list<string> $reasons
     * @param list<SideEffect> $sideEffects
     * @param list<string> $unreachableClasses
     * @return KeptClassification
     */
    private static function kept(array $reasons, array $sideEffects = [], array $unreachableClasses = []): array
    {
        return [
            'category' => 'kept',
            'reasons' => $reasons,
            'sideEffects' => $sideEffects,
            'unreachableClasses' => $unreachableClasses,
        ];
    }

    /**
     * Reports whether two paths point at the same file on disk. Path comparison
     * elsewhere is lexical (no filesystem access), which flags a false conflict
     * when an autoload directory or the repo root is reached through a symlink:
     * the two paths differ as strings but resolve to one inode, and at runtime
     * the require round-trips. This only ever downgrades a false hazard.
     */
    private function sameRealFile(string $a, string $b): bool
    {
        $realA = realpath($a);

        return $realA !== false && $realA === realpath($b);
    }

    /**
     * Collects the `files` entries of the autoload/autoload-dev sections.
     * Composer loads these eagerly on initialization, so a require_once whose
     * target is one of them is redundant regardless of what the file declares.
     *
     * When Composer has dumped its autoloader, the generated
     * `vendor/composer/autoload_files.php` is used: it already merges the root
     * project with every dependency's eager files, so a require of a
     * dependency's bootstrap/function file is recognized as redundant too.
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

        // Prefer Composer's dumped files map (root + dependencies, merged as
        // Composer loads them at runtime) when present.
        $generatedFiles = $this->repoRoot . '/vendor/composer/autoload_files.php';
        if (is_file($generatedFiles)) {
            return $this->collectGeneratedEagerFiles($generatedFiles);
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
     * Reads Composer's dumped `autoload_files.php` (a map of hash => absolute
     * file path) in an isolated scope, so its `$vendorDir`/`$baseDir` locals
     * never leak.
     *
     * @return array<string, true> Associative array keyed by absolute file path
     */
    private function collectGeneratedEagerFiles(string $generatedFile): array
    {
        $map = (static fn (): mixed => require $generatedFile)();
        if (!is_array($map)) {
            return [];
        }

        $files = [];
        foreach ($map as $path) {
            if (!is_string($path)) {
                continue;
            }
            $absolute = PathHelper::normalize($path);
            if (is_file($absolute)) {
                $files[$absolute] = true;
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
