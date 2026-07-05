<?php

declare(strict_types=1);

namespace Depone\Internal\Resolver;

/**
 * Reads composer.json once and exposes the normalized, repoRoot-applied
 * autoload configuration (`autoload` + `autoload-dev`): psr-4/psr-0 prefix
 * rules and classmap/files path entries.
 *
 * Tolerant of a missing, unreadable, or undecodable composer.json: in every
 * such case the config is simply empty. Callers that need to surface an error
 * for those conditions (e.g. AutoloadCandidateCollector) must check for them
 * themselves before constructing this class.
 *
 * @internal
 */
final class ComposerAutoloadConfig
{
    /** @var array<string, list<string>> PSR-4 rules (prefix => absolute dirs) */
    private array $psr4 = [];

    /** @var array<string, list<string>> PSR-0 rules (prefix => absolute dirs) */
    private array $psr0 = [];

    /** @var list<string> classmap entries (absolute file or directory paths) */
    private array $classmapEntries = [];

    /** @var list<string> files entries (absolute file paths) */
    private array $filesEntries = [];

    public function __construct(string $repoRoot)
    {
        $this->load(rtrim($repoRoot, '/'));
    }

    /** @return array<string, list<string>> */
    public function psr4(): array
    {
        return $this->psr4;
    }

    /** @return array<string, list<string>> */
    public function psr0(): array
    {
        return $this->psr0;
    }

    /** @return list<string> */
    public function classmapEntries(): array
    {
        return $this->classmapEntries;
    }

    /** @return list<string> */
    public function filesEntries(): array
    {
        return $this->filesEntries;
    }

    private function load(string $repoRoot): void
    {
        $composerPath = $repoRoot . '/composer.json';
        if (!file_exists($composerPath)) {
            return;
        }

        $json = file_get_contents($composerPath);
        if ($json === false) {
            return;
        }

        $composer = json_decode($json, true);
        if (!is_array($composer)) {
            return;
        }

        // Load both autoload and autoload-dev sections.
        foreach (['autoload', 'autoload-dev'] as $key) {
            if (!isset($composer[$key]) || !is_array($composer[$key])) {
                continue;
            }

            $autoload = $composer[$key];

            $this->collectPrefixedPaths($autoload['psr-4'] ?? null, $repoRoot, $this->psr4);
            $this->collectPrefixedPaths($autoload['psr-0'] ?? null, $repoRoot, $this->psr0);

            // classmap: files and directories, collected as-is (no rtrim/normalize).
            if (isset($autoload['classmap']) && is_array($autoload['classmap'])) {
                foreach ($autoload['classmap'] as $path) {
                    if (!is_string($path)) {
                        continue;
                    }
                    $this->classmapEntries[] = $repoRoot . '/' . $path;
                }
            }

            // files: eagerly loaded files, collected as-is (no rtrim/normalize).
            if (isset($autoload['files']) && is_array($autoload['files'])) {
                foreach ($autoload['files'] as $path) {
                    if (!is_string($path)) {
                        continue;
                    }
                    $this->filesEntries[] = $repoRoot . '/' . $path;
                }
            }
        }
    }

    /**
     * Collects prefix => directory rules (used for both psr-4 and psr-0), applying
     * the repoRoot and stripping trailing slashes exactly like AutoloadResolver did.
     *
     * @param array<string, list<string>> $target
     */
    private function collectPrefixedPaths(mixed $entries, string $repoRoot, array &$target): void
    {
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $prefix => $paths) {
            if (!is_string($prefix)) {
                continue;
            }
            $pathList = is_array($paths) ? $paths : [$paths];
            foreach ($pathList as $path) {
                if (!is_string($path)) {
                    continue;
                }
                $target[$prefix][] = $repoRoot . '/' . rtrim($path, '/');
            }
        }
    }
}
