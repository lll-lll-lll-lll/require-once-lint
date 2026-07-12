<?php

declare(strict_types=1);

namespace Depone\Internal\Tokenizer;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
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
use PhpParser\PrettyPrinter\Standard;

/**
 * Extracts fully qualified class/interface/trait/enum names declared in PHP
 * source code, and profiles what else a file does at the namespace top level.
 *
 * profile()->declaredClasses and profile()->topLevelClasses deliberately
 * disagree on guarded declarations (`if (!class_exists(...)) { class Foo {} }`):
 * declaredClasses finds a class-like anywhere in the file regardless of the
 * guard around it — the same view Composer's classmap generator takes, because
 * that is what actually ends up in a classmap (AutoloadResolver builds its
 * classmaps with Composer's own generator). The top-level view instead answers
 * "which classes does this file declare unconditionally" for the analyzer's
 * conflict/round-trip logic, which must not blame a polyfill for shadowing
 * the class it only conditionally stands in for.
 *
 * @phpstan-import-type SideEffect from TargetProfile
 *
 * @internal
 */
final class DeclaredClassExtractor
{
    /**
     * Side-effect excerpts are meant for report lines, not for reproducing the
     * source: long statements are cut off at this many bytes.
     */
    private const EXCERPT_MAX_BYTES = 80;

    private Parser $parser;
    private Standard $printer;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->printer = new Standard();
    }

    /**
     * Profiles the file in a single parse: declared classes (Composer's
     * classmap view), unconditional top-level classes, and the inventory of
     * top-level side effects. Unparsable source yields a profile with a single
     * `unparsable` side effect, so callers stay on the safe side (the require
     * is treated as needed rather than deletable).
     */
    public function profile(string $content): TargetProfile
    {
        try {
            $stmts = $this->parser->parse($content) ?? [];
        } catch (Error $e) {
            return new TargetProfile(false, [], [], [[
                'kind' => 'unparsable',
                'line' => max($e->getStartLine(), 0),
                'excerpt' => $e->getRawMessage(),
            ]]);
        }

        // Resolve names so each declaration carries its fully qualified name.
        // replaceNodes: false keeps the original names in the tree (the
        // resolver still adds namespacedName to declarations), so side-effect
        // excerpts print the code as written, not rewritten to FQCNs.
        $stmts = (new NodeTraverser(new NameResolver(null, ['replaceNodes' => false])))->traverse($stmts);

        $topLevelClasses = [];
        $sideEffects = [];
        foreach ($this->topLevelStmts($stmts) as $stmt) {
            if ($stmt instanceof ClassLike) {
                if ($stmt->name !== null) {
                    $topLevelClasses[] = $stmt->namespacedName?->toString() ?? $stmt->name->toString();
                }
                continue;
            }
            $kind = $this->sideEffectKind($stmt);
            if ($kind !== null) {
                $sideEffects[] = [
                    'kind' => $kind,
                    'line' => $stmt->getStartLine(),
                    'excerpt' => $this->excerpt($stmt),
                ];
            }
        }

        return new TargetProfile(
            true,
            $this->declaredClassNames($stmts),
            array_values(array_unique($topLevelClasses)),
            $sideEffects
        );
    }

    /**
     * Extracts the FQCNs of class-likes the file declares *unconditionally* at
     * the namespace top level. See {@see profile()}.
     *
     * @return list<string>
     */
    public function extractTopLevel(string $content): array
    {
        return $this->profile($content)->topLevelClasses;
    }

    /**
     * Reports whether the file is a "pure declaration file".
     * See {@see TargetProfile::declaresOnlyTypes()}.
     */
    public function declaresOnlyTypes(string $content): bool
    {
        return $this->profile($content)->declaresOnlyTypes();
    }

    /**
     * @param Node[] $stmts name-resolved statements
     * @return list<string>
     */
    private function declaredClassNames(array $stmts): array
    {
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
     * Yields the statements at the namespace top level, recursing only into
     * (braced or unbraced) namespace bodies and declare blocks. Every top-level
     * consumer goes through this, so they can never disagree on what "top
     * level" means — a nesting rule added here stays shared between the
     * conflict/round-trip check and the side-effect inventory.
     *
     * @param Node[] $stmts
     * @return iterable<Node\Stmt>
     */
    private function topLevelStmts(array $stmts): iterable
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                yield from $this->topLevelStmts($stmt->stmts);
            } elseif ($stmt instanceof Declare_ && $stmt->stmts !== null) {
                yield from $this->topLevelStmts($stmt->stmts);
            } elseif ($stmt instanceof Node\Stmt) {
                yield $stmt;
            }
        }
    }

    /**
     * Classifies a top-level statement's side effect, or null for statements
     * that carry none (declarations and pure scaffolding — the exact set that
     * keeps {@see TargetProfile::declaresOnlyTypes()} true).
     *
     * The kinds mirror what Composer autoload cannot reproduce: it loads
     * class-like declarations lazily and nothing else, so everything below is
     * a reason the require stays load-bearing.
     */
    private function sideEffectKind(Node\Stmt $stmt): ?string
    {
        if (
            $stmt instanceof ClassLike
            || $stmt instanceof Use_
            || $stmt instanceof GroupUse
            || $stmt instanceof Declare_
            || $stmt instanceof Nop
        ) {
            return null;
        }
        if ($stmt instanceof Stmt\Function_) {
            return 'function';
        }
        if ($stmt instanceof Stmt\Const_) {
            return 'constant';
        }
        if ($stmt instanceof Stmt\Return_) {
            return 'return';
        }
        if ($stmt instanceof Stmt\Echo_ || $stmt instanceof Stmt\InlineHTML) {
            return 'output';
        }
        if ($stmt instanceof Stmt\Global_ || $stmt instanceof Stmt\Static_) {
            return 'assignment';
        }
        if (
            $stmt instanceof Stmt\If_
            || $stmt instanceof Stmt\While_
            || $stmt instanceof Stmt\Do_
            || $stmt instanceof Stmt\For_
            || $stmt instanceof Stmt\Foreach_
            || $stmt instanceof Stmt\Switch_
            || $stmt instanceof Stmt\TryCatch
        ) {
            return 'control_flow';
        }
        if ($stmt instanceof Stmt\Expression) {
            return $this->expressionKind($stmt->expr);
        }

        return 'other';
    }

    private function expressionKind(Expr $expr): string
    {
        if ($expr instanceof Expr\FuncCall) {
            if ($expr->name instanceof Name) {
                $name = $expr->name->toLowerString();
                if ($name === 'define' || $name === 'ini_set') {
                    return $name;
                }
            }

            return 'call';
        }
        if (
            $expr instanceof Expr\MethodCall
            || $expr instanceof Expr\NullsafeMethodCall
            || $expr instanceof Expr\StaticCall
            || $expr instanceof Expr\New_
        ) {
            return 'call';
        }
        if ($expr instanceof Expr\Assign || $expr instanceof Expr\AssignOp || $expr instanceof Expr\AssignRef) {
            return 'assignment';
        }
        if ($expr instanceof Expr\Include_) {
            return 'include';
        }

        return 'other';
    }

    /**
     * Renders a one-line excerpt of the statement via php-parser's own printer
     * (normalized code, not a verbatim source slice), truncated for reports.
     */
    private function excerpt(Node\Stmt $stmt): string
    {
        // The printer renders attached comments before the node; the excerpt
        // must show the statement itself, not the doc block above it.
        $stmt = clone $stmt;
        $stmt->setAttribute('comments', []);
        $code = $this->printer->prettyPrint([$stmt]);
        $code = trim((string) preg_replace('/\s+/', ' ', $code));
        if (strlen($code) > self::EXCERPT_MAX_BYTES) {
            $code = substr($code, 0, self::EXCERPT_MAX_BYTES) . '…';
        }

        return $code;
    }
}
