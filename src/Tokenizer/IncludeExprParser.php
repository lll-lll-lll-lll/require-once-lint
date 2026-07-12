<?php

declare(strict_types=1);

namespace Depone\Internal\Tokenizer;

use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpToken;

/**
 * Parser for include/require statements and define() calls.
 *
 * The raw token stream locates the statements (token_get_all() keeps working
 * on files a strict parser would reject, which legacy codebases contain), but
 * the path expressions themselves are parsed and evaluated by php-parser's
 * ConstExprEvaluator rather than a hand-written expression parser. The
 * evaluator's fallback supplies the pieces that depend on analysis context:
 * `__DIR__`/`__FILE__` of the analyzed file, constants collected from
 * `define()` calls, and `dirname()`.
 *
 * @internal
 */
final class IncludeExprParser
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Parses a define() call and extracts the constant name and value.
     *
     * @param list<PhpToken> $tokens Token list
     * @param int $index Position of the `define` T_STRING token
     * @param array<string, string> $consts Map of known constants
     * @param string $file Path of the file currently being analyzed
     * @return array{0: string, 1: string}|null [constant name, constant value], or null on parse failure
     */
    public function parseDefine(array $tokens, int $index, array $consts, string $file): ?array
    {
        $cursor = $index + 1;
        TokenHelper::skipTrivia($tokens, $cursor);
        $openParen = $tokens[$cursor] ?? null;
        if ($openParen === null || $openParen->text !== '(') {
            return null;
        }

        $cursor++;
        [$argsTokens] = $this->readUntilMatchingCloseParen($tokens, $cursor);

        $call = $this->parseExpr('define(' . TokenHelper::tokensToString($argsTokens) . ')');
        if (!$call instanceof Expr\FuncCall || $call->isFirstClassCallable()) {
            return null;
        }
        $args = $call->getArgs();
        if (count($args) < 2 || $args[0]->unpack || $args[1]->unpack) {
            return null;
        }

        $constName = $this->evaluateToString($args[0]->value, $consts, $file);
        if ($constName === null || $constName === '') {
            return null;
        }
        $constValue = $this->evaluateToString($args[1]->value, $consts, $file);
        if ($constValue === null) {
            return null;
        }

        return [$constName, $constValue];
    }

    /**
     * Reads the argument tokens of a require/include statement.
     * Tokens are collected up to (but not including) the terminating `;`; a
     * leading parenthesized form such as `require_once('x.php')` is collected
     * with its parentheses intact — the expression parser treats it as
     * ordinary grouping.
     *
     * Example: require_once LIB_DIR . '/foo.php';  -> [LIB_DIR, '.', '/foo.php']
     * Example: require_once(LIB_DIR . '/foo.php'); -> ['(', LIB_DIR, '.', '/foo.php', ')']
     *
     * @param list<PhpToken> $tokens Token list
     * @param int $index Position of the require/include token
     * @return list<PhpToken> Argument token list
     */
    public function readIncludeExprTokens(array $tokens, int $index): array
    {
        $count = count($tokens);
        $cursor = $index + 1;
        TokenHelper::skipTrivia($tokens, $cursor);

        $exprTokens = [];
        while ($cursor < $count && $tokens[$cursor]->text !== ';') {
            $exprTokens[] = $tokens[$cursor];
            $cursor++;
        }

        return $exprTokens;
    }

    /**
     * Evaluates a statically resolvable expression and returns its path string.
     *
     * @param list<PhpToken> $tokens Expression token list
     * @param array<string, string> $consts Map of known constants
     * @param string $file Path of the file being analyzed, used for __DIR__ and __FILE__
     * @return string|null Evaluated path string, or null when it cannot be resolved
     */
    public function evalStaticExpr(array $tokens, array $consts, string $file): ?string
    {
        $expr = $this->parseExpr(TokenHelper::tokensToString($tokens));
        if ($expr === null) {
            return null;
        }

        return $this->evaluateToString($expr, $consts, $file);
    }

    /**
     * Classifies why an include expression could not be statically resolved,
     * from the AST rather than the raw tokens: the node kinds decide the
     * reason, and the earliest-starting marker in source order wins — the
     * same "first blocking construct" a left-to-right token scan reports. On
     * a position tie (a method call starts at its `$object` receiver) the
     * variable is the more precise reason. Snippets php-parser cannot parse
     * fall back to {@see TokenHelper::classifyUnresolvableReason()}.
     *
     * @param list<PhpToken> $tokens Expression token list
     * @return string Reason (variable, method_call, static_access, complex)
     */
    public function classifyUnresolvableReason(array $tokens): string
    {
        $expr = $this->parseExpr(TokenHelper::tokensToString($tokens));
        if ($expr === null) {
            return TokenHelper::classifyUnresolvableReason($tokens);
        }

        $ranks = [
            TokenHelper::REASON_VARIABLE => 0,
            TokenHelper::REASON_METHOD_CALL => 1,
            TokenHelper::REASON_STATIC_ACCESS => 2,
        ];
        $best = null;
        $bestKey = null;

        foreach ((new NodeFinder())->find([$expr], self::isUnresolvableMarker(...)) as $node) {
            $reason = self::markerReason($node);
            $key = [$node->getStartFilePos(), $ranks[$reason]];
            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $best = $reason;
            }
        }

        return $best ?? TokenHelper::REASON_COMPLEX;
    }

    private static function isUnresolvableMarker(Node $node): bool
    {
        return $node instanceof Expr\Variable
            || $node instanceof Expr\MethodCall
            || $node instanceof Expr\NullsafeMethodCall
            || $node instanceof Expr\PropertyFetch
            || $node instanceof Expr\NullsafePropertyFetch
            || $node instanceof Expr\StaticCall
            || $node instanceof Expr\StaticPropertyFetch
            || $node instanceof Expr\ClassConstFetch;
    }

    private static function markerReason(Node $node): string
    {
        if ($node instanceof Expr\Variable) {
            return TokenHelper::REASON_VARIABLE;
        }
        if (
            $node instanceof Expr\MethodCall
            || $node instanceof Expr\NullsafeMethodCall
            || $node instanceof Expr\PropertyFetch
            || $node instanceof Expr\NullsafePropertyFetch
        ) {
            return TokenHelper::REASON_METHOD_CALL;
        }

        return TokenHelper::REASON_STATIC_ACCESS;
    }

    /**
     * Parses a source snippet as a single expression, or null when it is not
     * one. The `\n` before the closing `;` keeps a trailing line comment in
     * the snippet from swallowing it.
     */
    private function parseExpr(string $source): ?Expr
    {
        try {
            $stmts = $this->parser->parse("<?php {$source}\n;");
        } catch (Error) {
            return null;
        }
        if ($stmts === null || count($stmts) !== 1 || !$stmts[0] instanceof Stmt\Expression) {
            return null;
        }

        return $stmts[0]->expr;
    }

    /**
     * Evaluates the expression with php-parser's constant-expression
     * evaluator. A path must be a string; anything the evaluator (plus the
     * context-dependent fallback) cannot reduce to one is unresolvable.
     *
     * @param array<string, string> $consts
     */
    private function evaluateToString(Expr $expr, array $consts, string $file): ?string
    {
        $evaluator = new ConstExprEvaluator(
            fn (Expr $unresolved): string => $this->evaluateFallback($unresolved, $consts, $file)
        );

        try {
            $value = $evaluator->evaluateSilently($expr);
        } catch (ConstExprEvaluationException) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * Supplies the context-dependent expressions the generic evaluator asks
     * for: magic constants of the analyzed file, define()'d constants, and
     * dirname() calls. Everything else is not statically resolvable.
     *
     * @param array<string, string> $consts
     * @throws ConstExprEvaluationException
     */
    private function evaluateFallback(Expr $expr, array $consts, string $file): string
    {
        if ($expr instanceof Expr\ConstFetch && $expr->name->isUnqualified()) {
            $name = $expr->name->toString();
            if (array_key_exists($name, $consts)) {
                return $consts[$name];
            }
        } elseif ($expr instanceof Scalar\MagicConst\Dir) {
            return dirname($file);
        } elseif ($expr instanceof Scalar\MagicConst\File) {
            return $file;
        } elseif ($expr instanceof Expr\FuncCall) {
            $dir = $this->evaluateDirname($expr, $consts, $file);
            if ($dir !== null) {
                return $dir;
            }
        }

        throw new ConstExprEvaluationException('expression is not statically resolvable');
    }

    /**
     * Evaluates dirname() calls: dirname(path) and dirname(path, levels) with
     * a positive integer-literal level, matching what dirname() itself accepts.
     *
     * @param array<string, string> $consts
     */
    private function evaluateDirname(Expr\FuncCall $call, array $consts, string $file): ?string
    {
        if (
            !$call->name instanceof Name
            || $call->name->toLowerString() !== 'dirname'
            || $call->isFirstClassCallable()
        ) {
            return null;
        }

        $args = $call->getArgs();
        if (count($args) < 1 || count($args) > 2 || $args[0]->unpack) {
            return null;
        }

        $path = $this->evaluateToString($args[0]->value, $consts, $file);
        if ($path === null) {
            return null;
        }

        $levels = 1;
        if (isset($args[1])) {
            $levelExpr = $args[1]->value;
            if ($args[1]->unpack || !$levelExpr instanceof Scalar\Int_ || $levelExpr->value < 1) {
                return null;
            }
            $levels = $levelExpr->value;
        }

        return dirname($path, $levels);
    }

    /**
     * Reads tokens until the matching closing parenthesis.
     * Assumes the cursor is positioned just after an opening `(` (depth = 1).
     *
     * @param list<PhpToken> $tokens Token list
     * @param int $cursor Position just after the opening `(`
     * @return array{0: list<PhpToken>, 1: int} [collected tokens, cursor pointing at the matching `)`]
     */
    private function readUntilMatchingCloseParen(array $tokens, int $cursor): array
    {
        $count = count($tokens);
        $collected = [];
        $depth = 1;
        while ($cursor < $count) {
            $token = $tokens[$cursor];
            if ($token->text === '(') {
                $depth++;
            } elseif ($token->text === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $collected[] = $token;
            $cursor++;
        }

        return [$collected, $cursor];
    }
}
