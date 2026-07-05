<?php

declare(strict_types=1);

namespace Depone\Internal\Core;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Depone\Internal\Exception\AnalyzerException;
use Depone\Internal\Resolver\ComposerAutoloadConfig;
use Depone\Internal\Tokenizer\PathHelper;
use SplFileInfo;

/**
 * Collects the candidate autoload file set (psr-4/psr-0/classmap entries) and the
 * eagerly loaded file set (`autoload.files`/`autoload-dev.files`) declared in
 * composer.json.
 *
 * Shared by Analyzer (which further filters candidates through a resolution
 * round-trip check) and AutoloadDoctor (which diagnoses unreachable candidates).
 *
 * @phpstan-type CandidateSet array{candidates: array<string, true>, files: array<string, true>}
 *
 * @internal
 */
final class AutoloadCandidateCollector
{
    private string $repoRoot;

    public function __construct(string $repoRoot)
    {
        $this->repoRoot = PathHelper::normalize($repoRoot);
    }

    /**
     * Reads composer.json and collects candidate and eager file sets.
     *
     * @return CandidateSet
     * @throws AnalyzerException
     */
    public function collect(): array
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

        $config = new ComposerAutoloadConfig($this->repoRoot);

        $files = [];
        $candidateFiles = [];

        // files: individual files
        $this->collectFromFiles($config->filesEntries(), $files);

        // classmap: directories or individual files
        $this->collectFromPaths($config->classmapEntries(), $candidateFiles);

        // psr-4 / psr-0: namespace => directory mapping
        $this->collectFromPaths($this->flattenDirs($config->psr4()), $candidateFiles);
        $this->collectFromPaths($this->flattenDirs($config->psr0()), $candidateFiles);

        return [
            'candidates' => $candidateFiles,
            'files' => $files,
        ];
    }

    /**
     * Flattens prefix => directories rules into a plain list of directories,
     * discarding the prefix (the candidate collector does not need it).
     *
     * @param array<string, list<string>> $rules
     * @return list<string>
     */
    private function flattenDirs(array $rules): array
    {
        $dirs = [];
        foreach ($rules as $paths) {
            foreach ($paths as $path) {
                $dirs[] = $path;
            }
        }

        return $dirs;
    }

    /**
     * Collects files from classmap/psr-4/psr-0 path entries (files or directories).
     *
     * @param list<string> $entries
     * @param array<string, true> $files Destination array, passed by reference
     */
    private function collectFromPaths(array $entries, array &$files): void
    {
        foreach ($entries as $entry) {
            $absolute = PathHelper::normalize($entry);
            $this->collectPhpFilesFromPath($absolute, $files);
        }
    }

    /**
     * Collects files from `files` entries.
     *
     * @param list<string> $entries
     * @param array<string, true> $files Destination array, passed by reference
     */
    private function collectFromFiles(array $entries, array &$files): void
    {
        foreach ($entries as $entry) {
            $absolute = PathHelper::normalize($entry);
            if (is_file($absolute)) {
                $files[$absolute] = true;
            }
        }
    }

    /**
     * Collects PHP files from the given path, whether it is a file or directory.
     *
     * @param array<string, true> $files Destination array, passed by reference
     * @throws AnalyzerException When a directory registered in composer.json cannot be scanned
     */
    private function collectPhpFilesFromPath(string $absolute, array &$files): void
    {
        if (is_dir($absolute)) {
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $info) {
                    /** @var SplFileInfo $info */
                    if ($info->isFile() && strtolower($info->getExtension()) === 'php') {
                        $files[PathHelper::normalize((string)$info->getPathname())] = true;
                    }
                }
            } catch (\UnexpectedValueException $e) {
                // An unreadable directory (or one deleted mid-scan) raises
                // UnexpectedValueException from the directory iterator; surface it
                // through the normal error path instead of crashing the command.
                throw new AnalyzerException("Failed to scan directory: {$absolute} ({$e->getMessage()})");
            }
            return;
        }

        if (is_file($absolute)) {
            $files[$absolute] = true;
        }
    }
}
