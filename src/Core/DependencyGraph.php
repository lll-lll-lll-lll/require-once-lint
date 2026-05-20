<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Core;

use RedundantRequireOnce\Tokenizer\PathHelper;

/**
 * Builds forward and reverse traces from dependency edges.
 */
final class DependencyGraph
{
    /** @var array<string, array<array{node: string, type: string}>> from -> [{node, type}, ...] */
    private array $forward = [];

    /** @var array<string, array<array{node: string, type: string}>> to -> [{node, type}, ...] */
    private array $reverse = [];

    private string $repoRoot;

    public function __construct(array $edges, string $repoRoot)
    {
        $this->repoRoot = PathHelper::normalize($repoRoot);
        foreach ($edges as $edge) {
            $this->forward[$edge['from']][] = ['node' => $edge['to'], 'type' => $edge['type']];
            $this->reverse[$edge['to']][] = ['node' => $edge['from'], 'type' => $edge['type']];
        }
    }

    /**
     * Builds a reverse trace showing which files require this file.
     *
     * @return array{target: string, directCallers: array, entrypoints: array, paths: array, truncated: bool}
     */
    public function buildReverseTrace(string $targetPath, int $maxPaths, int $maxDepth): array
    {
        $target = $this->toRelativePath($targetPath);
        $directCallers = $this->getUniqueNeighborNames($this->reverse, $target);

        [$paths, $truncated] = $this->collectPaths($this->reverse, $target, $maxPaths, $maxDepth, true);

        $entrypointList = $this->collectPathEndpoints($paths, 0);

        return [
            'target' => $target,
            'directCallers' => $directCallers,
            'entrypoints' => $entrypointList,
            'paths' => $paths,
            'truncated' => $truncated,
        ];
    }

    /**
     * Builds a forward trace showing what this file requires.
     *
     * @return array{target: string, directDependencies: array, leafFiles: array, paths: array, truncated: bool}
     */
    public function buildForwardTrace(string $targetPath, int $maxPaths, int $maxDepth): array
    {
        $target = $this->toRelativePath($targetPath);
        $directDependencies = $this->getUniqueNeighborNames($this->forward, $target);

        [$paths, $truncated] = $this->collectPaths($this->forward, $target, $maxPaths, $maxDepth, false);

        $leafFiles = $this->collectPathEndpoints($paths, -1);

        return [
            'target' => $target,
            'directDependencies' => $directDependencies,
            'leafFiles' => $leafFiles,
            'paths' => $paths,
            'truncated' => $truncated,
        ];
    }

    /**
     * Returns unique, sorted neighbor node names for the given node.
     */
    private function getUniqueNeighborNames(array $adjacencyList, string $node): array
    {
        $edges = $adjacencyList[$node] ?? [];
        $names = array_unique(array_column($edges, 'node'));
        sort($names);

        return $names;
    }

    /**
     * Returns adjacent edges (`node` + `type`) for the given node.
     *
     * @return array<array{node: string, type: string}>
     */
    private function getNeighborEdges(array $adjacencyList, string $node): array
    {
        return $adjacencyList[$node] ?? [];
    }

    /**
     * Collects paths with DFS.
     *
     * @param array $adjacencyList Adjacency list
     * @param string $start Start node
     * @param int $maxPaths Maximum number of paths (0 = unlimited)
     * @param int $maxDepth Maximum depth (0 = unlimited)
     * @param bool $reversePaths Whether to reverse the collected path order
     * @return array{0: array, 1: bool} [path list, truncated flag]
     */
    private function collectPaths(
        array $adjacencyList,
        string $start,
        int $maxPaths,
        int $maxDepth,
        bool $reversePaths
    ): array {
        $paths = [];
        // Paths are stored as [['node' => 'file', 'type' => null|'require_once'|'autoload'], ...].
        $startEntry = ['node' => $start, 'type' => null];
        $stack = [[$start, [$startEntry], [$start]]];
        $truncated = false;

        while ($stack !== [] && ($maxPaths === 0 || count($paths) < $maxPaths)) {
            [$node, $pathWithTypes, $visitedNodes] = array_pop($stack);
            $neighborEdges = $this->getNeighborEdges($adjacencyList, $node);

            // Finalize the path at a leaf node or once the max depth is reached.
            $reachedMaxDepth = $maxDepth > 0 && count($pathWithTypes) >= $maxDepth;
            if ($neighborEdges === [] || $reachedMaxDepth) {
                $paths[] = $reversePaths ? array_reverse($pathWithTypes) : $pathWithTypes;
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
            foreach (array_reverse($neighborEdges) as $edge) {
                $neighbor = $edge['node'];
                if (isset($seen[$neighbor]) || in_array($neighbor, $visitedNodes, true)) {
                    continue;
                }
                $seen[$neighbor] = true;
                $newEntry = ['node' => $neighbor, 'type' => $edge['type']];
                $stack[] = [
                    $neighbor,
                    array_merge($pathWithTypes, [$newEntry]),
                    array_merge($visitedNodes, [$neighbor]),
                ];
                $addedCount++;
            }

            // If every neighbor was excluded due to a cycle, record the current path as terminal.
            if ($addedCount === 0) {
                $paths[] = $reversePaths ? array_reverse($pathWithTypes) : $pathWithTypes;
            }
        }

        if ($stack !== []) {
            $truncated = true;
        }

        // Reverse traces need their edge types shifted to the correct position.
        if ($reversePaths) {
            $paths = array_map([$this, 'shiftTypesForReversePath'], $paths);
        }

        return [$paths, $truncated];
    }

    /**
     * Shifts edge types in a reversed path.
     *
     * Before reversing: [A(null), B(typeAB), C(typeBC), D(typeCD)]
     * After reversing:  [D(typeCD), C(typeBC), B(typeAB), A(null)]
     * Desired result:   [D(null), C(typeCD), B(typeBC), A(typeAB)]
     *
     * @param array<array{node: string, type: string|null}> $path
     * @return array<array{node: string, type: string|null}>
     */
    private function shiftTypesForReversePath(array $path): array
    {
        $count = count($path);
        if ($count <= 1) {
            return $path;
        }

        $result = [];
        for ($i = 0; $i < $count; $i++) {
            if ($i === 0) {
                // The first node has no incoming edge.
                $result[] = ['node' => $path[$i]['node'], 'type' => null];
            } else {
                // The edge into the i-th node comes from the previous entry's type.
                $result[] = ['node' => $path[$i]['node'], 'type' => $path[$i - 1]['type']];
            }
        }

        return $result;
    }

    /**
     * Collects path endpoints (first or last element) from a set of paths.
     *
     * @param int $index 0 = first element, -1 = last element
     */
    private function collectPathEndpoints(array $paths, int $index): array
    {
        $endpoints = [];
        foreach ($paths as $path) {
            if (count($path) > 1) {
                $entry = $index === -1 ? $path[count($path) - 1] : $path[0];
                $node = $entry['node'];
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
