<?php

declare(strict_types=1);

namespace Depone\Internal\Resolver;

use Depone\Internal\Tokenizer\DeclaredClassExtractor;
use FilesystemIterator;
use SplFileInfo;

/**
 * Resolves class names to file paths from Composer autoload settings.
 *
 * @phpstan-type VerboseResolution array{prefix: string|null, expectedPath: string|null, resolved: string|null}
 *
 * @internal
 */
final class AutoloadResolver
{
    /** @var array<string, list<string>> PSR-4 rules (prefix => directories) */
    private array $psr4 = [];

    /** @var array<string, list<string>> PSR-0 rules (prefix => directories) */
    private array $psr0 = [];

    /** @var array<string, string> classmap (class => file) */
    private array $classmap = [];

    private string $repoRoot;

    public function __construct(string $repoRoot)
    {
        $this->repoRoot = rtrim($repoRoot, '/');
        $this->loadComposerAutoload();
    }

    /**
     * Resolves a class name to a file path.
     *
     * @param string $className Fully qualified class name
     * @return string|null Absolute file path, or null when it cannot be resolved
     */
    public function resolve(string $className): ?string
    {
        // Strip a leading namespace separator.
        $className = ltrim($className, '\\');

        // Prefer classmap entries.
        if (isset($this->classmap[$className])) {
            return $this->classmap[$className];
        }

        // Try PSR-4 resolution first.
        $file = $this->resolvePsr4($className);
        if ($file !== null) {
            return $file;
        }

        // Fall back to PSR-0 resolution.
        return $this->resolvePsr0($className);
    }

    /**
     * Resolves a class name to a file path with details about which autoload
     * rule matched and where the class was expected to live.
     *
     * @param string $className Fully qualified class name
     * @return VerboseResolution
     */
    public function resolveVerbose(string $className): array
    {
        // Strip a leading namespace separator.
        $className = ltrim($className, '\\');

        $resolved = $this->resolve($className);

        $psr4Match = $this->matchPrefix($this->psr4, $className);
        if ($psr4Match !== null) {
            [$prefix, $dirs] = $psr4Match;
            $relativeClass = $prefix === '' ? $className : substr($className, strlen($prefix));
            $expectedPath = $dirs[0] . '/' . str_replace('\\', '/', $relativeClass) . '.php';

            return ['prefix' => $prefix, 'expectedPath' => $expectedPath, 'resolved' => $resolved];
        }

        $psr0Match = $this->matchPrefix($this->psr0, $className);
        if ($psr0Match !== null) {
            [$prefix, $dirs] = $psr0Match;
            $expectedPath = $this->psr0ExpectedPath($dirs[0], $className);

            return ['prefix' => $prefix, 'expectedPath' => $expectedPath, 'resolved' => $resolved];
        }

        return ['prefix' => null, 'expectedPath' => null, 'resolved' => $resolved];
    }

    /**
     * Finds the longest matching prefix (rules are pre-sorted longest-first) for
     * the given class name. An empty-string prefix always counts as a match.
     *
     * @param array<string, list<string>> $rules
     * @return array{0: string, 1: list<string>}|null
     */
    private function matchPrefix(array $rules, string $className): ?array
    {
        foreach ($rules as $prefix => $dirs) {
            if ($prefix === '' || str_starts_with($className, $prefix)) {
                return [$prefix, $dirs];
            }
        }

        return null;
    }

    /**
     * Derives the PSR-0 expected file path for a class name within the given directory.
     */
    private function psr0ExpectedPath(string $dir, string $className): string
    {
        // In PSR-0, underscores in the class portion map to directories.
        $lastNsPos = strrpos($className, '\\');
        if ($lastNsPos !== false) {
            $namespace = substr($className, 0, $lastNsPos);
            $shortClass = substr($className, $lastNsPos + 1);

            return $dir . '/' . str_replace('\\', '/', $namespace) . '/'
                . str_replace('_', '/', $shortClass) . '.php';
        }

        return $dir . '/' . str_replace('_', '/', $className) . '.php';
    }

    /**
     * Loads autoload settings from composer.json.
     */
    private function loadComposerAutoload(): void
    {
        $composerPath = $this->repoRoot . '/composer.json';
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

            // PSR-4
            if (isset($autoload['psr-4']) && is_array($autoload['psr-4'])) {
                foreach ($autoload['psr-4'] as $prefix => $paths) {
                    if (!is_string($prefix)) {
                        continue;
                    }
                    $pathList = is_array($paths) ? $paths : [$paths];
                    foreach ($pathList as $path) {
                        if (!is_string($path)) {
                            continue;
                        }
                        $this->psr4[$prefix][] = $this->repoRoot . '/' . rtrim($path, '/');
                    }
                }
            }

            // PSR-0
            if (isset($autoload['psr-0']) && is_array($autoload['psr-0'])) {
                foreach ($autoload['psr-0'] as $prefix => $paths) {
                    if (!is_string($prefix)) {
                        continue;
                    }
                    $pathList = is_array($paths) ? $paths : [$paths];
                    foreach ($pathList as $path) {
                        if (!is_string($path)) {
                            continue;
                        }
                        $this->psr0[$prefix][] = $this->repoRoot . '/' . rtrim($path, '/');
                    }
                }
            }

            // classmap: scan files and collect declared classes.
            if (isset($autoload['classmap']) && is_array($autoload['classmap'])) {
                foreach ($autoload['classmap'] as $path) {
                    if (!is_string($path)) {
                        continue;
                    }
                    $fullPath = $this->repoRoot . '/' . $path;
                    if (is_dir($fullPath)) {
                        $this->scanDirectoryForClasses($fullPath);
                    } elseif (is_file($fullPath)) {
                        $this->scanFileForClasses($fullPath);
                    }
                }
            }
        }

        // Sort longest prefixes first so more specific matches win.
        uksort($this->psr4, fn ($a, $b) => strlen($b) - strlen($a));
        uksort($this->psr0, fn ($a, $b) => strlen($b) - strlen($a));
    }

    /**
     * Resolves a class name with PSR-4 rules.
     */
    private function resolvePsr4(string $className): ?string
    {
        foreach ($this->psr4 as $prefix => $dirs) {
            if ($prefix === '' || str_starts_with($className, $prefix)) {
                $relativeClass = $prefix === '' ? $className : substr($className, strlen($prefix));
                foreach ($dirs as $dir) {
                    $file = $dir . '/' . str_replace('\\', '/', $relativeClass) . '.php';

                    if (file_exists($file)) {
                        return $file;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolves a class name with PSR-0 rules.
     */
    private function resolvePsr0(string $className): ?string
    {
        foreach ($this->psr0 as $prefix => $dirs) {
            if ($prefix === '' || str_starts_with($className, $prefix)) {
                foreach ($dirs as $dir) {
                    // In PSR-0, underscores in the class portion map to directories.
                    $lastNsPos = strrpos($className, '\\');
                    if ($lastNsPos !== false) {
                        $namespace = substr($className, 0, $lastNsPos);
                        $shortClass = substr($className, $lastNsPos + 1);
                        $file = $dir . '/' . str_replace('\\', '/', $namespace) . '/'
                            . str_replace('_', '/', $shortClass) . '.php';
                    } else {
                        $file = $dir . '/' . str_replace('_', '/', $className) . '.php';
                    }

                    if (file_exists($file)) {
                        return $file;
                    }
                }
            }
        }

        return null;
    }

    private function scanDirectoryForClasses(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->scanFileForClasses($file->getPathname());
            }
        }
    }

    /**
     * Extracts class, interface, trait, and enum names from a file and registers them in the classmap.
     */
    private function scanFileForClasses(string $filePath): void
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return;
        }

        $classExtractor = new DeclaredClassExtractor();
        foreach ($classExtractor->extract($content) as $fullClassName) {
            $this->classmap[$fullClassName] = $filePath;
        }
    }
}
