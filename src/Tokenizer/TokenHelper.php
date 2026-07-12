<?php

declare(strict_types=1);

namespace Depone\Internal\Tokenizer;

use PhpToken;

/**
 * Helper utilities for working with PHP tokens ({@see PhpToken}).
 *
 * @internal
 */
final class TokenHelper
{
    /** Unresolvable because the require_once expression contains a variable. */
    public const REASON_VARIABLE      = 'variable';
    /** Unresolvable because the expression contains an object method call. */
    public const REASON_METHOD_CALL   = 'method_call';
    /** Unresolvable because the expression contains static access (`::`). */
    public const REASON_STATIC_ACCESS = 'static_access';
    /** Unresolvable because the expression could not be classified into any of the above categories. */
    public const REASON_COMPLEX       = 'complex';

    /**
     * Skips trivia such as whitespace and comments.
     *
     * @param list<PhpToken> $tokens Token list
     * @param int $cursor Current position, updated by reference
     */
    public static function skipTrivia(array $tokens, int &$cursor): void
    {
        $count = count($tokens);
        while ($cursor < $count && $tokens[$cursor]->isIgnorable()) {
            $cursor++;
        }
    }

    /**
     * Reconstructs the original source code string from a token list.
     *
     * @param list<PhpToken> $tokens Token list
     * @return string Reconstructed source code
     */
    public static function tokensToString(array $tokens): string
    {
        return trim(implode('', array_map(
            static fn (PhpToken $token): string => $token->text,
            $tokens
        )));
    }

    /**
     * Classifies why a token list could not be resolved, by scanning the raw
     * tokens left to right and returning on the first marker found.
     *
     * This is the fallback for snippets php-parser cannot parse; parseable
     * expressions are classified from the AST by
     * {@see IncludeExprParser::classifyUnresolvableReason()}.
     *
     * @param list<PhpToken> $tokens Token list
     * @return string Reason (variable, method_call, static_access, complex)
     */
    public static function classifyUnresolvableReason(array $tokens): string
    {
        foreach ($tokens as $token) {
            if ($token->is(T_VARIABLE)) {
                return self::REASON_VARIABLE;
            }

            // Expressions containing $obj->method() or $obj?->method() are
            // not statically resolvable.
            if ($token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                return self::REASON_METHOD_CALL;
            }

            // Static access like Class::method() or Class::CONST is unresolved here.
            if ($token->is(T_DOUBLE_COLON)) {
                return self::REASON_STATIC_ACCESS;
            }
        }

        return self::REASON_COMPLEX;
    }
}
