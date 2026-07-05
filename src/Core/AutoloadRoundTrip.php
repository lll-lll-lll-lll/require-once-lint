<?php

declare(strict_types=1);

namespace Depone\Internal\Core;

use Depone\Internal\Resolver\AutoloadResolver;
use Depone\Internal\Tokenizer\DeclaredClassExtractor;
use Depone\Internal\Tokenizer\PathHelper;

/**
 * Performs the shared autoload "round trip" mechanics used by both Analyzer
 * and AutoloadDoctor: collect the candidate/eager file sets (via
 * AutoloadCandidateCollector), extract each candidate file's declared classes
 * (via DeclaredClassExtractor), and resolve each class back through
 * AutoloadResolver::resolveVerbose() to determine whether — and where — it
 * round-trips.
 *
 * @phpstan-import-type VerboseResolution from \Depone\Internal\Resolver\AutoloadResolver
 * @phpstan-type RoundTripClass array{name: string, verbose: VerboseResolution}
 * @phpstan-type RoundTripCandidate array{file: string, classes: list<RoundTripClass>}
 * @phpstan-type RoundTripResult array{candidates: list<RoundTripCandidate>, eager: array<string, true>}
 *
 * @internal
 */
final class AutoloadRoundTrip
{
    private string $repoRoot;

    public function __construct(string $repoRoot)
    {
        $this->repoRoot = PathHelper::normalize($repoRoot);
    }

    /**
     * @return RoundTripResult
     */
    public function collect(): array
    {
        $collector = new AutoloadCandidateCollector($this->repoRoot);
        $collected = $collector->collect();

        $resolver = new AutoloadResolver($this->repoRoot);
        $classExtractor = new DeclaredClassExtractor();

        $candidates = [];
        foreach (array_keys($collected['candidates']) as $filePath) {
            $classes = [];
            foreach ($this->extractDeclaredClassNames($classExtractor, $filePath) as $className) {
                $classes[] = [
                    'name' => $className,
                    'verbose' => $resolver->resolveVerbose($className),
                ];
            }

            $candidates[] = [
                'file' => PathHelper::normalize($filePath),
                'classes' => $classes,
            ];
        }

        return [
            'candidates' => $candidates,
            'eager' => $collected['files'],
        ];
    }

    /**
     * Reads the given file and extracts the declared class names (FQCN) it contains.
     * Returns an empty array when the file cannot be read.
     *
     * @return list<string>
     */
    private function extractDeclaredClassNames(DeclaredClassExtractor $classExtractor, string $filePath): array
    {
        $content = file_get_contents($filePath);
        if (!is_string($content)) {
            return [];
        }

        return $classExtractor->extract($content);
    }
}
