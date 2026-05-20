<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Tokenizer;

/**
 * Helper utilities for working with PHP tokens.
 */
final class TokenHelper
{
    /** Unresolvable because the require_once expression contains a variable. */
    public const REASON_VARIABLE      = 'variable';
    /** Unresolvable because the expression contains an object method call. */
    public const REASON_METHOD_CALL   = 'method_call';
    /** Unresolvable because the expression contains static access (`::`). */
    public const REASON_STATIC_ACCESS = 'static_access';
    /** Unresolvable because the expression contains an unknown constant. */
    public const REASON_UNKNOWN_CONST = 'unknown_const';
    /** Unresolvable because the expression is too complex to classify above. */
    public const REASON_COMPLEX       = 'complex';
    /** Fallback used when no reason could be determined. */
    public const REASON_UNKNOWN       = 'unknown';

    /**
     * Returns the token ID. For single-character tokens this is null.
     */
    public static function id(Token $token): ?int
    {
        return $token->id;
    }

    /**
     * Returns the token text.
     */
    public static function text(Token $token): string
    {
        return $token->text;
    }

    /**
     * Returns the token line number. Single-character tokens use 0.
     */
    public static function line(Token $token): int
    {
        return $token->line;
    }

    /**
     * Returns whether the token can be part of a class name.
     * Includes T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, and T_NAME_RELATIVE on PHP 8.0+.
     *
     * @param int|null $id Token ID
     */
    public static function isNameToken(?int $id): bool
    {
        if ($id === null) {
            return false;
        }

        return in_array($id, [
            T_STRING,
            T_NS_SEPARATOR,
            T_NAME_QUALIFIED,
            T_NAME_FULLY_QUALIFIED,
            T_NAME_RELATIVE,
        ], true);
    }

    /**
     * Skips trivia such as whitespace and comments.
     *
     * @param list<Token> $tokens Token list
     * @param int $cursor Current position, updated by reference
     */
    public static function skipTrivia(array $tokens, int &$cursor): void
    {
        $count = count($tokens);
        while ($cursor < $count) {
            $id = self::id($tokens[$cursor]);
            if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                break;
            }
            $cursor++;
        }
    }

    /**
     * Splits comma-separated argument tokens.
     * Nested parentheses are respected, so only top-level commas split arguments.
     *
     * @param list<Token> $tokens Token list
     * @return array<list<Token>> Token lists for each argument
     */
    public static function splitArgs(array $tokens): array
    {
        $args = [];
        $current = [];
        $depth = 0;

        foreach ($tokens as $token) {
            if ($token->text === '(') {
                $depth++;
            } elseif ($token->text === ')') {
                $depth--;
            } elseif ($token->text === ',' && $depth === 0) {
                $args[] = $current;
                $current = [];
                continue;
            }
            $current[] = $token;
        }

        if ($current !== []) {
            $args[] = $current;
        }

        return $args;
    }

    /**
     * Removes surrounding quotes from a string literal and unescapes it.
     *
     * @param string $literal Quoted string literal such as "'foo'" or '"bar"'
     * @return string|null Unquoted string, or null when the literal is malformed
     */
    public static function stripQuotes(string $literal): ?string
    {
        $length = strlen($literal);
        if ($length < 2) {
            return null;
        }
        $quote = $literal[0];
        if (($quote !== "'" && $quote !== '"') || $literal[$length - 1] !== $quote) {
            return null;
        }

        $inner = substr($literal, 1, -1);

        // Double-quoted strings use C-style escape sequences.
        if ($quote === '"') {
            return stripcslashes($inner);
        }

        // Single-quoted strings only unescape \' and \\.
        return strtr($inner, [
            "\\'" => "'",
            '\\\\' => '\\',
        ]);
    }

    /**
     * Reconstructs the original source code string from a token list.
     *
     * @param list<Token> $tokens Token list
     * @return string Reconstructed source code
     */
    public static function tokensToString(array $tokens): string
    {
        $result = '';
        foreach ($tokens as $token) {
            $result .= self::text($token);
        }

        return trim($result);
    }

    /**
     * Classifies why a token list could not be resolved.
     *
     * @param list<Token> $tokens Token list
     * @return string Reason (variable, method_call, static_access, unknown_const, complex)
     */
    public static function classifyUnresolvableReason(array $tokens): string
    {
        foreach ($tokens as $token) {
            $id = self::id($token);

            if ($id === T_VARIABLE) {
                return self::REASON_VARIABLE;
            }

            // Expressions containing $obj->method() are not statically resolvable.
            if ($id === T_OBJECT_OPERATOR) {
                return self::REASON_METHOD_CALL;
            }

            // Static access like Class::method() or Class::CONST is unresolved here.
            if ($id === T_DOUBLE_COLON) {
                return self::REASON_STATIC_ACCESS;
            }
        }

        // A constant-like token exists, but it could not be resolved.
        foreach ($tokens as $token) {
            $id = self::id($token);
            if ($id === T_STRING) {
                $text = self::text($token);
                // Uppercase-leading identifiers are treated as likely constants.
                if (ctype_upper($text[0])) {
                    return self::REASON_UNKNOWN_CONST;
                }
            }
        }

        return self::REASON_COMPLEX;
    }
}
