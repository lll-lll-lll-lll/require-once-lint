<?php

declare(strict_types=1);

namespace Depone\Internal\Core;

use Depone\Internal\Tokenizer\PathHelper;

/**
 * Builds reverse traces (which files require a given file) from require/include edges.
 *
 * @phpstan-import-type Edge from \Depone\Internal\Core\Analyzer
 * @phpstan-type TracePath list<string>
 * @phpstan-type TraceResult array{target: string, directCallers: list<string>, entrypoints: list<string>, paths: list<TracePath>, truncated: bool}
 *
 * @internal
 */
final class DependencyGraph
{
    /** @var array<string, list<string>> to -> [from, ...] */
    private array $reverse = [];

    private string $repoRoot;

    /**
     * @param list<Edge> $edges
     */
    public function __construct(array $edges, string $repoRoot)
    {
        $this->repoRoot = PathHelper::normalize($repoRoot);
        foreach ($edges as $edge) {
            $this->reverse[$edge['to']][] = $edge['from'];
        }
    }

    /**
     * Builds a reverse trace showing which files require this file.
     *
     * @return TraceResult
     */
    public function buildReverseTrace(string $targetPath, int $maxPaths, int $maxDepth): array
    {
        $target = $this->toRelativePath($targetPath);
        $directCallers = $this->getUniqueNeighborNames($target);

        [$paths, $truncated] = $this->collectPaths($target, $maxPaths, $maxDepth);

        $entrypointList = $this->collectPathEndpoints($paths);

        return [
            'target' => $target,
            'directCallers' => $directCallers,
            'entrypoints' => $entrypointList,
            'paths' => $paths,
            'truncated' => $truncated,
        ];
    }

    /**
     * Returns unique, sorted neighbor node names for the given node.
     *
     * @return list<string>
     */
    private function getUniqueNeighborNames(string $node): array
    {
        $names = array_unique($this->reverse[$node] ?? []);
        sort($names);

        return $names;
    }

    /**
     * Collects paths with DFS.
     *
     * The adjacency list always represents reverse (caller) edges, so each
     * collected path is reversed into entrypoint-to-target order.
     *
     * @param string $start Start node
     * @param int $maxPaths Maximum number of paths (0 = unlimited)
     * @param int $maxDepth Maximum depth (0 = unlimited)
     * @return array{0: list<TracePath>, 1: bool} [path list, truncated flag]
     */
    private function collectPaths(string $start, int $maxPaths, int $maxDepth): array
    {
        $paths = [];
        /** @var list<array{0: string, 1: TracePath, 2: list<string>}> $stack */
        $stack = [[$start, [$start], [$start]]];
        $truncated = false;

        while ($stack !== [] && ($maxPaths === 0 || count($paths) < $maxPaths)) {
            [$node, $path, $visitedNodes] = array_pop($stack);
            $neighbors = $this->reverse[$node] ?? [];

            // Finalize the path at a leaf node or once the max depth is reached.
            $reachedMaxDepth = $maxDepth > 0 && count($path) >= $maxDepth;
            if ($neighbors === [] || $reachedMaxDepth) {
                $paths[] = array_reverse($path);
                if ($reachedMaxDepth) {
                    $truncated = true;
                }
                continue;
            }

            // Push neighbors onto the stack while excluding cycles.
            $addedCount = 0;
            // Process each neighbor only once. If multiple edges point to the same
            // node, use the first one encountered.
            $seen = [];
            foreach (array_reverse($neighbors) as $neighbor) {
                if (isset($seen[$neighbor]) || in_array($neighbor, $visitedNodes, true)) {
                    continue;
                }
                $seen[$neighbor] = true;
                $stack[] = [
                    $neighbor,
                    array_merge($path, [$neighbor]),
                    array_merge($visitedNodes, [$neighbor]),
                ];
                $addedCount++;
            }

            // If every neighbor was excluded due to a cycle, record the current path as terminal.
            if ($addedCount === 0) {
                $paths[] = array_reverse($path);
            }
        }

        if ($stack !== []) {
            $truncated = true;
        }

        return [$paths, $truncated];
    }

    /**
     * Collects path endpoints (the entrypoint, i.e. the first element) from a set of paths.
     *
     * @param list<TracePath> $paths
     * @return list<string>
     */
    private function collectPathEndpoints(array $paths): array
    {
        $endpoints = [];
        foreach ($paths as $path) {
            if (count($path) > 1) {
                $node = $path[0];
                $endpoints[$node] = true;
            }
        }
        $result = array_keys($endpoints);
        sort($result);

        return $result;
    }

    /**
     * Converts a path to a repository-relative path.
     */
    private function toRelativePath(string $path): string
    {
        // Normalize absolute paths as-is.
        if ($path !== '' && $path[0] === '/') {
            $normalized = PathHelper::normalize($path);
        } else {
            $normalized = PathHelper::normalize($this->repoRoot . '/' . $path);
        }

        return PathHelper::toRelative($normalized, $this->repoRoot);
    }
}
