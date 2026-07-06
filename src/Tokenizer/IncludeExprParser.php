<?php

declare(strict_types=1);

namespace Depone\Internal\Tokenizer;

/**
 * Parser for include/require statements and define() calls.
 *
 * @internal
 */
final class IncludeExprParser
{
    /**
     * Parses a define() call and extracts the constant name and value.
     *
     * @param list<Token> $tokens Token list
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
        [$argsTokens, $cursor] = $this->readUntilMatchingCloseParen($tokens, $cursor);

        $args = TokenHelper::splitArgs($argsTokens);
        if (count($args) < 2) {
            return null;
        }

        $constName = $this->evalStaticExpr($args[0], $consts, $file);
        if ($constName === null || $constName === '') {
            return null;
        }
        $constValue = $this->evalStaticExpr($args[1], $consts, $file);
        if ($constValue === null) {
            return null;
        }

        return [$constName, $constValue];
    }

    /**
     * Reads the argument tokens of a require/include statement.
     * Tokens are collected up to (but not including) the terminating `;` or a
     * `?>` close tag (a statement may end with either); a leading parenthesized
     * form such as `require_once('x.php')` is collected with its parentheses
     * intact and left for StaticExprParser's grouping branch to evaluate.
     *
     * Example: require_once LIB_DIR . '/foo.php';  -> [LIB_DIR, '.', '/foo.php']
     * Example: require_once(LIB_DIR . '/foo.php'); -> ['(', LIB_DIR, '.', '/foo.php', ')']
     *
     * @param list<Token> $tokens Token list
     * @param int $index Position of the require/include token
     * @return list<Token> Argument token list
     */
    public function readIncludeExprTokens(array $tokens, int $index): array
    {
        $count = count($tokens);
        $cursor = $index + 1;
        TokenHelper::skipTrivia($tokens, $cursor);

        $exprTokens = [];
        while (
            $cursor < $count
            && $tokens[$cursor]->text !== ';'
            && $tokens[$cursor]->id !== T_CLOSE_TAG
        ) {
            $exprTokens[] = $tokens[$cursor];
            $cursor++;
        }

        return $exprTokens;
    }

    /**
     * Evaluates a statically resolvable expression and returns its path string.
     *
     * @param list<Token> $tokens Expression token list
     * @param array<string, string> $consts Map of known constants
     * @param string $file Path of the file being analyzed, used for __DIR__ and __FILE__
     * @return string|null Evaluated path string, or null when it cannot be resolved
     */
    public function evalStaticExpr(array $tokens, array $consts, string $file): ?string
    {
        $parser = new StaticExprParser($tokens, $consts, $file);

        return $parser->parse();
    }

    /**
     * Reads tokens until the matching closing parenthesis.
     * Assumes the cursor is positioned just after an opening `(` (depth = 1).
     *
     * @param list<Token> $tokens Token list
     * @param int $cursor Position just after the opening `(`
     * @return array{0: list<Token>, 1: int} [collected tokens, cursor pointing at the matching `)`]
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
