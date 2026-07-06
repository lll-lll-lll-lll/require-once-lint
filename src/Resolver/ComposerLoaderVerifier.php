<?php

declare(strict_types=1);

namespace Depone\Internal\Resolver;

use Composer\Autoload\ClassLoader;
use Depone\Internal\Exception\AnalyzerException;
use Depone\Internal\Tokenizer\PathHelper;

/**
 * Cross-checks depone's static autoload resolution against the maps Composer
 * actually dumped for the analyzed project, under its `vendor/composer/`
 * directory -- the ground truth `vendor/autoload.php` would use at runtime.
 *
 * SAFETY: this class never requires the analyzed project's
 * `vendor/autoload.php`. That file executes every `autoload.files` entry as a
 * side effect of being loaded, which would violate depone's core guarantee of
 * never running the code it analyzes. It only ever loads the dumped map
 * files themselves -- `autoload_psr4.php`, `autoload_namespaces.php`,
 * `autoload_classmap.php`, `autoload_files.php` -- which are pure
 * `return array(...)` documents, each through a scope-isolated closure so
 * their local `$vendorDir`/`$baseDir` variables never leak into this class.
 * The maps are then fed into a fresh `Composer\Autoload\ClassLoader` instance
 * that is never `register()`ed, so it never participates in autoloading
 * either.
 *
 * @phpstan-import-type RedundantEntry from \Depone\Internal\Core\Analyzer
 * @phpstan-import-type RedundantProof from \Depone\Internal\Core\Analyzer
 * @phpstan-type VerifyFailure array{file: string, line: int, target: string, class: string|null, loader_path: string|null, reason: string}
 *
 * @internal
 */
final class ComposerLoaderVerifier
{
    /**
     * The dumped files that together constitute a usable Composer autoload
     * dump. Shared by the constructor (which loads them) and isAvailable()
     * (which only checks for their presence), so the two can't drift apart.
     * `autoload_files.php` is not listed: it is optional, only present when
     * the analyzed project declares `autoload.files` entries.
     */
    private const MAP_PSR4 = 'autoload_psr4.php';
    private const MAP_PSR0 = 'autoload_namespaces.php';
    private const MAP_CLASSMAP = 'autoload_classmap.php';
    private const REQUIRED_MAPS = [self::MAP_PSR4, self::MAP_PSR0, self::MAP_CLASSMAP];

    private ClassLoader $loader;

    /** @var array<string, true> realpath-normalized `autoload.files` targets */
    private array $eagerFiles = [];

    public function __construct(string $repoRoot)
    {
        if (!class_exists(ClassLoader::class)) {
            throw new AnalyzerException('Composer\Autoload\ClassLoader is not available');
        }

        $composerDir = self::composerDir($repoRoot);

        $psr4 = self::loadPathMap($composerDir . '/' . self::MAP_PSR4);
        $namespaces = self::loadPathMap($composerDir . '/' . self::MAP_PSR0);
        $classmap = self::loadClassMap($composerDir . '/' . self::MAP_CLASSMAP);
        $filesMap = $composerDir . '/autoload_files.php';
        $files = is_file($filesMap) ? self::loadFilesMap($filesMap) : [];

        $loader = new ClassLoader();
        foreach ($psr4 as $prefix => $paths) {
            $loader->setPsr4($prefix, $paths);
        }
        foreach ($namespaces as $prefix => $paths) {
            $loader->set($prefix, $paths);
        }
        $loader->addClassMap($classmap);
        // Deliberately never register()ed: this loader exists only to answer
        // findFile() questions, not to participate in autoloading.
        $this->loader = $loader;

        foreach ($files as $file) {
            $real = realpath($file);
            if ($real !== false) {
                $this->eagerFiles[$real] = true;
            }
        }
    }

    /**
     * Reports whether Composer's dumped autoload maps are present for the
     * given repository root, i.e. whether a ComposerLoaderVerifier can be
     * constructed for it at all.
     *
     * This is a fast-path convenience for a friendly CLI message before the
     * analysis runs -- it is not a load-bearing caller obligation. The
     * constructor enforces its own precondition (via loadMap()) regardless of
     * whether a caller checked isAvailable() first.
     */
    public static function isAvailable(string $repoRoot): bool
    {
        $composerDir = self::composerDir($repoRoot);

        foreach (self::REQUIRED_MAPS as $map) {
            if (!is_file($composerDir . '/' . $map)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns the `vendor/composer/` directory Composer dumps its autoload
     * maps into, for the given analyzed-project root.
     */
    private static function composerDir(string $repoRoot): string
    {
        return rtrim($repoRoot, '/') . '/vendor/composer';
    }

    /**
     * Asks Composer's own (dumped) ClassLoader where the given class would
     * load from, and compares it against the file depone statically resolved
     * it to.
     *
     * @return array{status: 'verified', loaderPath: null}|array{status: 'unknown', loaderPath: null}|array{status: 'mismatch', loaderPath: string}
     */
    public function verifyClass(string $class, string $targetAbsolute): array
    {
        $found = $this->loader->findFile($class);
        if ($found === false) {
            // Absent from the dump entirely: the dump is stale, or the class
            // was renamed/removed since the last `composer dump-autoload`.
            return ['status' => 'unknown', 'loaderPath' => null];
        }

        $foundReal = realpath($found);
        $targetReal = realpath($targetAbsolute);
        if ($foundReal !== false && $foundReal === $targetReal) {
            return ['status' => 'verified', 'loaderPath' => null];
        }

        return ['status' => 'mismatch', 'loaderPath' => $found];
    }

    /**
     * Reports whether the given absolute path is one of the `autoload.files`
     * entries in Composer's dump.
     */
    public function verifyEagerTarget(string $targetAbsolute): bool
    {
        $real = realpath($targetAbsolute);

        return $real !== false && isset($this->eagerFiles[$real]);
    }

    /**
     * Cross-checks every redundant finding against Composer's dumped autoload
     * maps. Only redundant findings are checked: fixable/conflicting/needed
     * already assert that the require is broken or hazardous rather than
     * deletable, so there is nothing to double-check there. A mismatch here
     * means either the dump is stale (run `composer dump-autoload`) or
     * depone's own static resolution is wrong; either way it must reach the
     * user before they delete anything.
     *
     * @param list<RedundantEntry> $redundant
     * @return array{entries: list<RedundantEntry>, mismatches: list<VerifyFailure>}
     */
    public function verifyFindings(array $redundant, string $repoRoot): array
    {
        // Per-target memo: legacy repos require the same file from many
        // sites, and the verify outcome is identical for every site sharing a
        // target -- the same reason Analyzer memoizes classifications per
        // target. The target's absolute path is only derived once per
        // distinct target too.
        /** @var array<string, array{verified: bool, failures: list<array{class: string|null, loader_path: string|null, reason: string}>}> $memo */
        $memo = [];
        $entries = [];
        $mismatches = [];

        foreach ($redundant as $entry) {
            $target = $entry['target'];

            if (!array_key_exists($target, $memo)) {
                $targetAbsolute = PathHelper::normalize($repoRoot . '/' . $target);
                $memo[$target] = $this->verifyTarget($entry['proof'], $targetAbsolute, $repoRoot);
            }

            $outcome = $memo[$target];
            $entries[] = [...$entry, 'verified' => $outcome['verified']];

            foreach ($outcome['failures'] as $failure) {
                $mismatches[] = [
                    'file' => $entry['file'],
                    'line' => $entry['line'],
                    'target' => $entry['target'],
                    'class' => $failure['class'],
                    'loader_path' => $failure['loader_path'],
                    'reason' => $failure['reason'],
                ];
            }
        }

        return ['entries' => $entries, 'mismatches' => $mismatches];
    }

    /**
     * Verifies one distinct redundant-finding target against the dumped
     * autoload maps, independent of which require site(s) point at it -- the
     * per-site file/line/target stamping happens in verifyFindings(), which
     * memoizes this per normalized target.
     *
     * @param RedundantProof $proof
     * @return array{verified: bool, failures: list<array{class: string|null, loader_path: string|null, reason: string}>}
     */
    private function verifyTarget(array $proof, string $targetAbsolute, string $repoRoot): array
    {
        $failures = [];

        if ($proof['eager']) {
            if (!$this->verifyEagerTarget($targetAbsolute)) {
                $failures[] = [
                    'class' => null,
                    'loader_path' => null,
                    'reason' => 'target is not an autoload.files entry in composer\'s dump — run composer dump-autoload',
                ];
            }

            return ['verified' => $failures === [], 'failures' => $failures];
        }

        foreach ($proof['classes'] as $evidence) {
            $check = $this->verifyClass($evidence['class'], $targetAbsolute);
            if ($check['status'] === 'verified') {
                continue;
            }

            if ($check['status'] === 'mismatch') {
                $failures[] = [
                    'class' => $evidence['class'],
                    'loader_path' => PathHelper::toRelative($check['loaderPath'], $repoRoot),
                    'reason' => 'composer loader resolves a different file',
                ];
            } else {
                $failures[] = [
                    'class' => $evidence['class'],
                    'loader_path' => null,
                    'reason' => 'class not present in composer\'s dumped autoload — run composer dump-autoload',
                ];
            }
        }

        return ['verified' => $failures === [], 'failures' => $failures];
    }

    /**
     * Loads a Composer-generated map file -- a pure `return array(...)`
     * document -- through a scope-isolated closure, so its local
     * `$vendorDir`/`$baseDir` variables never leak into this class, and
     * validates that it actually returned an array.
     *
     * @return array<array-key, mixed>
     */
    private static function loadMap(string $path): array
    {
        if (!is_file($path)) {
            throw new AnalyzerException("composer autoload map not found: {$path}");
        }

        $map = (static function (string $path) {
            return require $path;
        })($path);

        if (!is_array($map)) {
            throw new AnalyzerException("composer autoload map did not return an array: {$path}");
        }

        return $map;
    }

    /**
     * Loads a PSR-4/PSR-0 prefix map (`autoload_psr4.php`,
     * `autoload_namespaces.php`): prefix => one or several directories. A
     * single directory is normalized to a one-element list here (ClassLoader
     * accepts arrays for both setPsr4() and set()), so the return type
     * collapses to a single shape instead of a union callers have to
     * re-check.
     *
     * @return array<string, list<string>>
     */
    private static function loadPathMap(string $path): array
    {
        $map = [];
        foreach (self::loadMap($path) as $prefix => $paths) {
            if (!is_string($prefix)) {
                throw new AnalyzerException("composer autoload map has a non-string prefix: {$path}");
            }

            if (is_string($paths)) {
                $map[$prefix] = [$paths];
                continue;
            }

            if (!is_array($paths)) {
                throw new AnalyzerException("composer autoload map has an invalid path list: {$path}");
            }

            $list = [];
            foreach ($paths as $item) {
                if (!is_string($item)) {
                    throw new AnalyzerException("composer autoload map has a non-string path: {$path}");
                }
                $list[] = $item;
            }
            $map[$prefix] = $list;
        }

        return $map;
    }

    /**
     * Loads the classmap (`autoload_classmap.php`): class => file.
     *
     * @return array<string, string>
     */
    private static function loadClassMap(string $path): array
    {
        $map = [];
        foreach (self::loadMap($path) as $class => $file) {
            if (!is_string($class) || !is_string($file)) {
                throw new AnalyzerException("composer autoload map has a non-string entry: {$path}");
            }
            $map[$class] = $file;
        }

        return $map;
    }

    /**
     * Loads the `autoload.files` map (`autoload_files.php`): hash => file.
     * Only the files themselves matter here, not their hashes.
     *
     * @return list<string>
     */
    private static function loadFilesMap(string $path): array
    {
        $files = [];
        foreach (self::loadMap($path) as $file) {
            if (!is_string($file)) {
                throw new AnalyzerException("composer autoload map has a non-string entry: {$path}");
            }
            $files[] = $file;
        }

        return $files;
    }
}
