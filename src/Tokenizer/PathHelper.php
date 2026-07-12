<?php

declare(strict_types=1);

namespace Depone\Internal\Tokenizer;

use Symfony\Component\Filesystem\Path;

/**
 * Path handling, delegated to symfony/filesystem's {@see Path} instead of a
 * hand-rolled implementation: canonicalization (backslashes, `.`/`..`) and
 * absolute-path detection follow the library, so Windows drive-letter paths
 * (`C:\...`) are recognized as absolute too.
 *
 * @internal
 */
final class PathHelper
{
    /**
     * Normalizes a path by converting backslashes to slashes and resolving `.` and `..`.
     *
     * @param string $path Input path
     * @return string Normalized path
     */
    public static function normalize(string $path): string
    {
        return Path::canonicalize($path);
    }

    /**
     * Converts an absolute path to a path relative to the repository root.
     *
     * @param string $absolute Absolute path
     * @param string $repoRoot Absolute repository root path
     * @return string Relative path, or the original absolute path when it is outside the repository
     */
    public static function toRelative(string $absolute, string $repoRoot): string
    {
        $absolute = Path::canonicalize($absolute);
        $repoRoot = Path::canonicalize($repoRoot);

        // The repo root itself stays absolute: only paths strictly inside it
        // have a meaningful repo-relative form.
        if ($absolute === $repoRoot || !Path::isBasePath($repoRoot, $absolute)) {
            return $absolute;
        }

        return Path::makeRelative($absolute, $repoRoot);
    }

    /**
     * Resolves a require/include path to an absolute path.
     *
     * - absolute paths are normalized and returned as-is
     * - relative paths are resolved from the file being analyzed
     *
     * @param string $rawValue Evaluated path string
     * @param string $contextFile Absolute path of the file being analyzed
     * @return string Resolved absolute path
     */
    public static function resolveRequiredPath(string $rawValue, string $contextFile): string
    {
        return Path::makeAbsolute($rawValue, Path::getDirectory($contextFile));
    }
}
