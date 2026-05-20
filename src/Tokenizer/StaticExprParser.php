<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Tokenizer;

/**
 * Evaluates static PHP expressions with a recursive-descent parser.
 *
 * Supported syntax:
 * - string literals ('...', "...")
 * - string concatenation (.)
 * - constants (for example BASE_DIR and LIB_DIR)
 * - magic constants (__DIR__, __FILE__)
 * - dirname() function calls
 * - parenthesized grouping
 */
final class StaticExprParser
{
    /** @var list<Token> */
    private array $tokens;
    private array $consts;
    private string $file;
    private int $cursor = 0;

    /**
     * @param list<Token> $tokens
     */
    public function __construct(array $tokens, array $consts, string $file)
    {
        $this->tokens = $tokens;
        $this->consts = $consts;
        $this->file = $file;
    }

    /**
     * Parses the full token stream and returns the resulting string value.
     * Returns null when the parser cannot consume the entire token stream.
     */
    public function parse(): ?string
    {
        $value = $this->parseConcat();
        $this->skipTrivia();
        if ($value === null || $this->cursor < count($this->tokens)) {
            return null;
        }

        return $value;
    }

    /**
     * Parses concatenation with the `.` operator using left associativity.
     */
    private function parseConcat(): ?string
    {
        $left = $this->parsePrimary();
        if ($left === null) {
            return null;
        }

        while (true) {
            $this->skipTrivia();
            $token = $this->tokens[$this->cursor] ?? null;
            if ($token === null || $token->text !== '.') {
                break;
            }
            $this->cursor++;
            $right = $this->parsePrimary();
            if ($right === null) {
                return null;
            }
            $left .= $right;
        }

        return $left;
    }

    /**
     * Parses a primary expression such as a literal, constant, grouped
     * expression, or dirname() call.
     */
    private function parsePrimary(): ?string
    {
        $this->skipTrivia();
        $token = $this->tokens[$this->cursor] ?? null;
        if ($token === null) {
            return null;
        }

        if ($token->text === '(') {
            $this->cursor++;
            $value = $this->parseConcat();
            $this->skipTrivia();
            $next = $this->tokens[$this->cursor] ?? null;
            if ($next === null || $next->text !== ')') {
                return null;
            }
            $this->cursor++;

            return $value;
        }

        if ($token->id === null) {
            return null;
        }

        $id = $token->id;
        $text = $token->text;

        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            $this->cursor++;

            return TokenHelper::stripQuotes($text);
        }

        if ($id === T_DIR) {
            $this->cursor++;

            return dirname($this->file);
        }

        if ($id === T_FILE) {
            $this->cursor++;

            return $this->file;
        }

        if ($id === T_LNUMBER) {
            $this->cursor++;

            return $text;
        }

        // Identifier token: dispatch to dirname() (case-insensitive per PHP function semantics)
        // or a known constant (case-sensitive per PHP 8 define() semantics).
        // Unknown identifiers are intentionally not guessed at; they fall through to null.
        if ($id === T_STRING) {
            $this->cursor++;
            $lower = strtolower($text);

            if ($lower === 'dirname') {
                return $this->parseDirnameFunction();
            }

            if (array_key_exists($text, $this->consts)) {
                return $this->consts[$text];
            }

            return null;
        }

        return null;
    }

    /**
     * Parses dirname() calls. Supports dirname(path) and dirname(path, levels).
     */
    private function parseDirnameFunction(): ?string
    {
        $this->skipTrivia();
        $openParen = $this->tokens[$this->cursor] ?? null;
        if ($openParen === null || $openParen->text !== '(') {
            return null;
        }
        $this->cursor++;

        $path = $this->parseConcat();
        if ($path === null) {
            return null;
        }

        $levels = 1;
        $this->skipTrivia();
        $comma = $this->tokens[$this->cursor] ?? null;
        if ($comma !== null && $comma->text === ',') {
            $this->cursor++;
            $this->skipTrivia();
            $levelToken = $this->tokens[$this->cursor] ?? null;
            if ($levelToken === null || $levelToken->id !== T_LNUMBER) {
                return null;
            }
            $levels = (int)$levelToken->text;
            if ($levels <= 0) {
                return null;
            }
            $this->cursor++;
        }

        $this->skipTrivia();
        $closeParen = $this->tokens[$this->cursor] ?? null;
        if ($closeParen === null || $closeParen->text !== ')') {
            return null;
        }
        $this->cursor++;

        for ($i = 0; $i < $levels; $i++) {
            $path = dirname($path);
        }

        return $path;
    }

    private function skipTrivia(): void
    {
        TokenHelper::skipTrivia($this->tokens, $this->cursor);
    }
}
