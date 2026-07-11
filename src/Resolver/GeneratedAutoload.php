<?php

declare(strict_types=1);

namespace Depone\Internal\Resolver;

use Depone\Internal\Exception\AnalyzerException;
use Throwable;

/**
 * Reads Composer's dumped autoload maps (`vendor/composer/autoload_*.php`).
 *
 * "Has Composer dumped its autoloader?" is a single fact, decided here once
 * against one sentinel (`autoload_psr4.php`, which Composer writes on every
 * dump) for every consumer: either every map comes from the dump, or none
 * does. Deciding it per consumer against different files would mix a dumped
 * view of classes with a composer.json view of eager files (or vice versa) —
 * describing a runtime that never exists.
 *
 * Loading a map executes the generated PHP file (in an isolated scope, so its
 * `$vendorDir`/`$baseDir` locals never leak). A map that does not load —
 * corrupt, truncated, or hand-edited — surfaces as an AnalyzerException so
 * the CLI reports a clean analysis error instead of an uncaught throwable.
 *
 * @internal
 */
final class GeneratedAutoload
{
    private function __construct(private string $dir)
    {
    }

    /**
     * Returns the dumped autoloader of the repository, or null when none has
     * been generated (the caller should fall back to composer.json).
     */
    public static function locate(string $repoRoot): ?self
    {
        $dir = rtrim($repoRoot, '/') . '/vendor/composer';

        // Composer writes the PSR-4 map on every dump; its absence means
        // autoload has not been generated.
        if (!is_file($dir . '/autoload_psr4.php')) {
            return null;
        }

        return new self($dir);
    }

    /**
     * @return array<string, list<string>> PSR-4 prefix => directories
     * @throws AnalyzerException
     */
    public function psr4(): array
    {
        return $this->prefixMap('autoload_psr4.php');
    }

    /**
     * @return array<string, list<string>> PSR-0 prefix => directories
     * @throws AnalyzerException
     */
    public function psr0(): array
    {
        return $this->prefixMap('autoload_namespaces.php');
    }

    /**
     * @return array<string, string> class => absolute file path
     * @throws AnalyzerException
     */
    public function classmap(): array
    {
        $classmap = [];
        foreach ($this->loadMap('autoload_classmap.php') as $class => $file) {
            if (is_string($class) && is_string($file)) {
                $classmap[$class] = $file;
            }
        }

        return $classmap;
    }

    /**
     * Eager `files` entries (root + every dependency), merged as Composer
     * loads them at runtime. Composer omits `autoload_files.php` entirely
     * when no `files` entries exist, which reads as an empty list — not as
     * a reason to fall back to composer.json.
     *
     * @return list<string> Absolute file paths
     * @throws AnalyzerException
     */
    public function eagerFiles(): array
    {
        $files = [];
        foreach ($this->loadMap('autoload_files.php') as $path) {
            if (is_string($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @return array<string, list<string>> prefix => directories
     * @throws AnalyzerException
     */
    private function prefixMap(string $name): array
    {
        $prefixes = [];
        foreach ($this->loadMap($name) as $prefix => $dirs) {
            if (!is_string($prefix)) {
                continue;
            }
            foreach ((array) $dirs as $path) {
                if (is_string($path)) {
                    $prefixes[$prefix][] = rtrim($path, '/');
                }
            }
        }

        return $prefixes;
    }

    /**
     * Includes a generated map in an isolated scope and always returns an
     * array; an absent map reads as empty.
     *
     * @return array<mixed>
     * @throws AnalyzerException
     */
    private function loadMap(string $name): array
    {
        $file = $this->dir . '/' . $name;
        if (!is_file($file)) {
            return [];
        }

        try {
            $map = (static fn (): mixed => require $file)();
        } catch (Throwable $e) {
            throw new AnalyzerException("Failed to load Composer autoload map {$file}: {$e->getMessage()}");
        }

        return is_array($map) ? $map : [];
    }
}
