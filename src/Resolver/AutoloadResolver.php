<?php

declare(strict_types=1);

namespace Depone\Internal\Resolver;

use Depone\Internal\Tokenizer\DeclaredClassExtractor;
use FilesystemIterator;
use SplFileInfo;

/**
 * Resolves class names to file paths from Composer autoload settings.
 *
 * When Composer has dumped its autoloader (`vendor/composer/autoload_*.php`
 * present), those generated maps are used: they already merge the root project
 * with every installed dependency exactly as Composer resolves them at runtime,
 * so a class provided by a dependency resolves too. Without a dumped autoloader
 * (autoload never generated, e.g. a project that has not run `composer install`)
 * the resolver falls back to parsing the root `composer.json` on its own.
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
        // Prefer Composer's dumped autoloader (root + dependencies, merged as
        // Composer sees them at runtime); fall back to reading composer.json
        // directly when autoload has not been generated.
        if (!$this->loadGeneratedAutoload()) {
            $this->loadComposerAutoload();
        }
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
     * Loads Composer's dumped autoload maps (`vendor/composer/autoload_*.php`).
     *
     * These generated files already merge the root project and every installed
     * dependency, and hold pre-computed absolute paths, so they are the most
     * faithful picture of what actually loads at runtime — including
     * dependency-provided classes and any `exclude-from-classmap` Composer
     * applied when dumping.
     *
     * @return bool True when a dumped autoloader was found and loaded; false
     *              when none exists (caller should fall back to composer.json).
     */
    private function loadGeneratedAutoload(): bool
    {
        $dir = $this->repoRoot . '/vendor/composer';
        $psr4File = $dir . '/autoload_psr4.php';

        // Composer always generates the PSR-4 map alongside the others. Its
        // absence means autoload has not been dumped: signal a fallback.
        if (!is_file($psr4File)) {
            return false;
        }

        foreach ($this->requireMap($psr4File) as $prefix => $dirs) {
            if (!is_string($prefix)) {
                continue;
            }
            foreach ((array) $dirs as $path) {
                if (is_string($path)) {
                    $this->psr4[$prefix][] = rtrim($path, '/');
                }
            }
        }

        $psr0File = $dir . '/autoload_namespaces.php';
        if (is_file($psr0File)) {
            foreach ($this->requireMap($psr0File) as $prefix => $dirs) {
                if (!is_string($prefix)) {
                    continue;
                }
                foreach ((array) $dirs as $path) {
                    if (is_string($path)) {
                        $this->psr0[$prefix][] = rtrim($path, '/');
                    }
                }
            }
        }

        $classmapFile = $dir . '/autoload_classmap.php';
        if (is_file($classmapFile)) {
            foreach ($this->requireMap($classmapFile) as $class => $file) {
                // Composer already applied first-wins when dumping, but keep the
                // guard so resolve()'s classmap precedence stays authoritative.
                if (is_string($class) && is_string($file)) {
                    $this->classmap[$class] ??= $file;
                }
            }
        }

        // Sort longest prefixes first so more specific matches win.
        uksort($this->psr4, fn ($a, $b) => strlen($b) - strlen($a));
        uksort($this->psr0, fn ($a, $b) => strlen($b) - strlen($a));

        return true;
    }

    /**
     * Includes a Composer-generated autoload map in an isolated scope — so its
     * `$vendorDir`/`$baseDir` locals never leak — and always returns an array.
     *
     * @return array<mixed>
     */
    private function requireMap(string $file): array
    {
        $map = (static fn (): mixed => require $file)();

        return is_array($map) ? $map : [];
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

        $files = [];
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        // RecursiveDirectoryIterator yields entries in raw filesystem order,
        // which varies by platform and directory history. Sorting first makes
        // which file wins a same-name classmap collision (see
        // scanFileForClasses()) deterministic and independent of that order.
        sort($files);

        foreach ($files as $filePath) {
            $this->scanFileForClasses($filePath);
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
            // Composer's ClassMapGenerator keeps the FIRST occurrence of a
            // duplicate class name across classmap-scanned files. Ties here
            // must break the same way, or a require of the file Composer
            // actually ignores gets classified against the one it doesn't:
            // conflicting and redundant swap places relative to runtime.
            $this->classmap[$fullClassName] ??= $filePath;
        }
    }
}
