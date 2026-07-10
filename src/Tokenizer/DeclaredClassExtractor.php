<?php

declare(strict_types=1);

namespace Depone\Internal\Tokenizer;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Extracts fully qualified class/interface/trait/enum names declared in PHP
 * source code, and tells whether a file is a "pure declaration file".
 *
 * @internal
 */
final class DeclaredClassExtractor
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Extracts declared class names (FQCN) from the given source code.
     * Anonymous classes (`new class { ... }`) are excluded and duplicate names
     * are removed. Returns an empty array when the source cannot be parsed.
     *
     * @return list<string>
     */
    public function extract(string $content): array
    {
        $stmts = $this->parse($content);
        if ($stmts === null) {
            return [];
        }

        // Resolve names so each declaration carries its fully qualified name.
        $stmts = (new NodeTraverser(new NameResolver()))->traverse($stmts);

        $names = [];
        foreach ((new NodeFinder())->findInstanceOf($stmts, ClassLike::class) as $node) {
            /** @var ClassLike $node */
            if ($node->name === null) {
                continue; // anonymous class
            }
            $names[] = $node->namespacedName?->toString() ?? $node->name->toString();
        }

        return array_values(array_unique($names));
    }

    /**
     * Reports whether the file is a "pure declaration file": at the namespace
     * top level it contains nothing but class/interface/trait/enum declarations
     * (plus the allowed scaffolding — `declare`, `use` imports). This mirrors
     * PSR-1's "a file should declare symbols OR cause side effects, not both".
     *
     * It matters because Composer autoload only ever loads class-like
     * declarations, and only lazily on first reference. If a required file also
     * defines functions or constants, or runs top-level statements, autoload
     * does not reproduce those, so the require is load-bearing and must not be
     * called redundant even when its classes are autoload-reachable.
     *
     * The check errs toward reporting side effects: unparsable source and any
     * unrecognized top-level statement count, so the caller stays on the safe
     * side (treats the require as needed rather than deletable).
     */
    public function declaresOnlyTypes(string $content): bool
    {
        $stmts = $this->parse($content);

        return $stmts !== null && $this->onlyDeclarations($stmts);
    }

    /**
     * @param Node\Stmt[] $stmts
     */
    private function onlyDeclarations(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            // A (braced or unbraced) namespace's body is itself top level, as is
            // a declare block; recurse into either.
            if ($stmt instanceof Namespace_) {
                if (!$this->onlyDeclarations($stmt->stmts)) {
                    return false;
                }
                continue;
            }
            if ($stmt instanceof Declare_ && $stmt->stmts !== null) {
                if (!$this->onlyDeclarations($stmt->stmts)) {
                    return false;
                }
                continue;
            }

            // Declarations and pure scaffolding carry no side effect.
            $allowed = $stmt instanceof ClassLike
                || $stmt instanceof Use_
                || $stmt instanceof GroupUse
                || $stmt instanceof Declare_
                || $stmt instanceof Nop;
            if (!$allowed) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parses source into a statement list, or null when it cannot be parsed.
     *
     * @return Node\Stmt[]|null
     */
    private function parse(string $content): ?array
    {
        try {
            return $this->parser->parse($content);
        } catch (Error) {
            return null;
        }
    }
}
