<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Tests;

use PHPUnit\Framework\TestCase;
use RedundantRequireOnce\Tokenizer\StaticExprParser;
use RedundantRequireOnce\Tokenizer\Token;

/**
 * Unit tests for StaticExprParser.
 *
 * Covered behavior:
 *   - string literals (single and double quoted)
 *   - string concatenation (.)
 *   - magic constants (__DIR__, __FILE__)
 *   - user-defined constants ($consts)
 *   - dirname() calls (with and without levels)
 *   - parenthesized grouping
 *   - numeric literals (T_LNUMBER)
 *   - unresolvable cases (variables, unknown constants, incomplete expressions, etc.)
 */
final class StaticExprParserTest extends TestCase
{
    /** Fake file path used in tests. */
    private const FILE = '/project/src/Foo.php';

    /**
     * Tokenizes a PHP expression string, passes it to StaticExprParser, and returns parse().
     *
     * @param array<string, string> $consts
     */
    private function parse(string $phpExpr, array $consts = [], string $file = self::FILE): ?string
    {
        $tokens = Token::tokenize('<?php ' . $phpExpr);
        // Remove the leading T_OPEN_TAG token.
        return (new StaticExprParser(array_slice($tokens, 1), $consts, $file))->parse();
    }

    // -------------------------------------------------------------------------
    // String literals
    // -------------------------------------------------------------------------

    public function testSingleQuotedString(): void
    {
        self::assertSame('hello.php', $this->parse("'hello.php'"));
    }

    public function testDoubleQuotedString(): void
    {
        self::assertSame('hello.php', $this->parse('"hello.php"'));
    }

    public function testEmptyString(): void
    {
        self::assertSame('', $this->parse("''"));
    }

    public function testSingleQuoteEscape(): void
    {
        // 'it\'s' -> it's
        self::assertSame("it's", $this->parse("'it\\'s'"));
    }

    public function testDoubleQuoteNewlineEscape(): void
    {
        // "\n" -> the actual newline character
        self::assertSame("\n", $this->parse('"\n"'));
    }

    public function testDoubleQuoteBackslashEscape(): void
    {
        // "\\" -> a single backslash character
        self::assertSame('\\', $this->parse('"\\\\"'));
    }

    // -------------------------------------------------------------------------
    // String concatenation
    // -------------------------------------------------------------------------

    public function testTwoStringsConcatenated(): void
    {
        self::assertSame('/src/foo.php', $this->parse("'/src/' . 'foo.php'"));
    }

    public function testMultipleConcatenation(): void
    {
        self::assertSame('abc', $this->parse("'a' . 'b' . 'c'"));
    }

    public function testConcatenationWithWhitespace(): void
    {
        // Whitespace around the concatenation operator should be ignored.
        self::assertSame('foobar', $this->parse("'foo'  .  'bar'"));
    }

    // -------------------------------------------------------------------------
    // Magic constants __DIR__ / __FILE__
    // -------------------------------------------------------------------------

    public function testDirMagicConstant(): void
    {
        // __DIR__ returns the file's directory.
        self::assertSame('/project/src', $this->parse('__DIR__'));
    }

    public function testFileMagicConstant(): void
    {
        // __FILE__ returns the full file path.
        self::assertSame(self::FILE, $this->parse('__FILE__'));
    }

    public function testDirConcatenatedWithPath(): void
    {
        self::assertSame('/project/src/Bar.php', $this->parse("__DIR__ . '/Bar.php'"));
    }

    public function testFileConcatenatedWithSuffix(): void
    {
        self::assertSame('/project/src/Foo.php.bak', $this->parse("__FILE__ . '.bak'"));
    }

    public function testDirInDeepNestedFile(): void
    {
        $file = '/a/b/c/d/e.php';
        self::assertSame('/a/b/c/d', $this->parse('__DIR__', [], $file));
    }

    // -------------------------------------------------------------------------
    // User-defined constants
    // -------------------------------------------------------------------------

    public function testKnownConstant(): void
    {
        $consts = ['LIB_DIR' => '/var/www/inc/'];
        self::assertSame('/var/www/inc/', $this->parse('LIB_DIR', $consts));
    }

    public function testKnownConstantConcatenated(): void
    {
        $consts = ['LIB_DIR' => '/var/www/inc/'];
        self::assertSame('/var/www/inc/util.php', $this->parse("LIB_DIR . 'util.php'", $consts));
    }

    public function testKnownConstantWithEmptyValue(): void
    {
        // Constants with an empty string value should still resolve correctly.
        $consts = ['EMPTY_CONST' => ''];
        self::assertSame('', $this->parse('EMPTY_CONST', $consts));
    }

    public function testMultipleKnownConstantsConcatenated(): void
    {
        $consts = [
            'BASE_DIR' => '/var/www',
            'MOD_PATH' => '/modules',
        ];
        self::assertSame('/var/www/modules/foo.php', $this->parse("BASE_DIR . MOD_PATH . '/foo.php'", $consts));
    }

    public function testUnknownConstantReturnsNull(): void
    {
        self::assertNull($this->parse('UNKNOWN_CONST'));
    }

    public function testUnknownConstantWithEmptyConstsReturnsNull(): void
    {
        self::assertNull($this->parse('SOME_DIR', []));
    }

    public function testDirnameKeywordInConstsIsIgnoredAsFunctionCall(): void
    {
        // Even if 'dirname' exists in $consts, it is treated as a function call
        // candidate, so the bare identifier still returns null.
        $consts = ['dirname' => '/should/not/be/used'];
        self::assertNull($this->parse('dirname', $consts));
    }

    // -------------------------------------------------------------------------
    // dirname() calls
    // -------------------------------------------------------------------------

    public function testDirnameWithFileMagicConstant(): void
    {
        self::assertSame('/project/src', $this->parse('dirname(__FILE__)'));
    }

    public function testDirnameWithDirMagicConstant(): void
    {
        // dirname(__DIR__) resolves to the parent directory of __DIR__.
        self::assertSame('/project', $this->parse('dirname(__DIR__)'));
    }

    public function testDirnameWithStringLiteral(): void
    {
        self::assertSame('/foo/bar', $this->parse("dirname('/foo/bar/baz.php')"));
    }

    public function testDirnameWithLevels2(): void
    {
        // dirname(__FILE__, 2) -> two levels up
        self::assertSame('/project', $this->parse('dirname(__FILE__, 2)'));
    }

    public function testDirnameWithLevel1SameAsDefault(): void
    {
        // levels=1 should behave the same as omitting the second argument.
        self::assertSame('/project/src', $this->parse('dirname(__FILE__, 1)'));
    }

    public function testDirnameWithLevel0ReturnsNull(): void
    {
        // levels <= 0 is treated as unresolvable to avoid silent coercion.
        self::assertNull($this->parse('dirname(__FILE__, 0)'));
    }

    public function testDirnameWithNegativeLevelReturnsNull(): void
    {
        // T_LNUMBER is an unsigned integer token, so -1 becomes
        // T_MINUS + T_LNUMBER and therefore returns null here.
        self::assertNull($this->parse('dirname(__FILE__, -1)'));
    }

    public function testDirnameWithLargeLevel(): void
    {
        // When levels exceed the path depth, follow PHP's standard dirname behavior.
        $file = '/a/b.php';
        self::assertSame('/', $this->parse('dirname(__FILE__, 100)', [], $file));
    }

    public function testDirnameConcatenation(): void
    {
        self::assertSame('/project/src/config.php', $this->parse("dirname(__FILE__) . '/config.php'"));
    }

    public function testNestedDirname(): void
    {
        // dirname(dirname(__FILE__)) should match dirname(__FILE__, 2).
        self::assertSame('/project', $this->parse('dirname(dirname(__FILE__))'));
    }

    public function testDirnameWithLevelsConcatenated(): void
    {
        self::assertSame('/project/shared', $this->parse("dirname(__FILE__, 2) . '/shared'"));
    }

    public function testDirnameWithoutParenReturnsNull(): void
    {
        // Without '(', dirname cannot be parsed as a function call.
        self::assertNull($this->parse("dirname . '/foo.php'"));
    }

    public function testDirnameWithNoArgumentsReturnsNull(): void
    {
        // dirname() with no arguments returns null.
        self::assertNull($this->parse('dirname()'));
    }

    public function testDirnameWithNonLnumberLevelReturnsNull(): void
    {
        // A string level is not T_LNUMBER, so the expression returns null.
        self::assertNull($this->parse("dirname('/foo/bar', 'two')"));
    }

    public function testDirnameWithUnresolvableArgumentReturnsNull(): void
    {
        // Unknown constants inside arguments are not resolvable.
        self::assertNull($this->parse('dirname(UNKNOWN_PATH)'));
    }

    // -------------------------------------------------------------------------
    // Parenthesized grouping
    // -------------------------------------------------------------------------

    public function testParenthesesGrouping(): void
    {
        self::assertSame('foobar', $this->parse("('foo' . 'bar')"));
    }

    public function testParenthesesWithOuterConcat(): void
    {
        self::assertSame('/project/src/lib/foo.php', $this->parse("(dirname(__FILE__) . '/lib') . '/foo.php'"));
    }

    public function testNestedParentheses(): void
    {
        self::assertSame('abc', $this->parse("(('a' . 'b') . 'c')"));
    }

    public function testUnclosedParenReturnsNull(): void
    {
        // Missing a closing parenthesis should return null.
        self::assertNull($this->parse("('foo' . 'bar'"));
    }

    public function testEmptyParenReturnsNull(): void
    {
        // () returns null because parseConcat yields no expression.
        self::assertNull($this->parse('()'));
    }

    // -------------------------------------------------------------------------
    // Numeric literals (T_LNUMBER)
    // -------------------------------------------------------------------------

    public function testNumericLiteral(): void
    {
        self::assertSame('42', $this->parse('42'));
    }

    public function testZeroNumericLiteral(): void
    {
        self::assertSame('0', $this->parse('0'));
    }

    public function testNumericLiteralConcatenated(): void
    {
        self::assertSame('/path/to/42', $this->parse("'/path/to/' . 42"));
    }

    // -------------------------------------------------------------------------
    // Unresolvable cases -> null
    // -------------------------------------------------------------------------

    public function testVariableReturnsNull(): void
    {
        self::assertNull($this->parse('$var'));
    }

    public function testVariableInConcatReturnsNull(): void
    {
        self::assertNull($this->parse("__DIR__ . '/' . \$file"));
    }

    public function testUnknownFunctionReturnsNull(): void
    {
        // Function calls other than dirname() are not supported.
        self::assertNull($this->parse('realpath(__DIR__)'));
    }

    public function testTrailingTokensReturnNull(): void
    {
        // Extra trailing tokens mean the full expression cannot be consumed.
        // Example: 'foo' 'bar' (two literals with no concatenation operator)
        self::assertNull($this->parse("'foo' 'bar'"));
    }

    public function testEmptyTokensReturnNull(): void
    {
        // Empty token input makes parsePrimary return null, so parse() returns null.
        $result = (new StaticExprParser([], [], self::FILE))->parse();
        self::assertNull($result);
    }

    public function testIncompleteExpressionTrailingDotReturnsNull(): void
    {
        // No expression follows the concatenation operator.
        self::assertNull($this->parse("'foo' ."));
    }

    public function testRightOperandOfConcatIsUnresolvableReturnsNull(): void
    {
        // An unknown constant on the right-hand side makes the concat unresolved.
        self::assertNull($this->parse("'prefix/' . UNKNOWN"));
    }
}
