<?php

declare(strict_types=1);

namespace Depone\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Depone\Internal\Tokenizer\Token;
use Depone\Internal\Tokenizer\TokenHelper;

/**
 * Unit tests for TokenHelper::classifyUnresolvableReason().
 *
 * Covered behavior:
 *   - variable / method_call / static_access / complex classification
 *   - classification scans tokens left-to-right and returns on the first
 *     match, so a variable appearing before a later `->` or `::` wins
 */
final class TokenHelperTest extends TestCase
{
    /**
     * @return list<Token>
     */
    private function tokensFor(string $phpExpr): array
    {
        $tokens = Token::tokenize('<?php ' . $phpExpr);
        // Remove the leading T_OPEN_TAG token.
        return array_slice($tokens, 1);
    }

    public function testVariableIsClassifiedAsVariable(): void
    {
        self::assertSame(
            TokenHelper::REASON_VARIABLE,
            TokenHelper::classifyUnresolvableReason($this->tokensFor('$path'))
        );
    }

    public function testMethodCallOnFunctionResultIsClassifiedAsMethodCall(): void
    {
        // No variable precedes the `->`, so this is classified as method_call.
        self::assertSame(
            TokenHelper::REASON_METHOD_CALL,
            TokenHelper::classifyUnresolvableReason($this->tokensFor('getConfig()->path()'))
        );
    }

    public function testStaticAccessIsClassifiedAsStaticAccess(): void
    {
        self::assertSame(
            TokenHelper::REASON_STATIC_ACCESS,
            TokenHelper::classifyUnresolvableReason($this->tokensFor('Loader::PATH'))
        );
    }

    public function testStaticMethodCallIsClassifiedAsStaticAccess(): void
    {
        self::assertSame(
            TokenHelper::REASON_STATIC_ACCESS,
            TokenHelper::classifyUnresolvableReason($this->tokensFor('Loader::getPath()'))
        );
    }

    public function testUnresolvableConstantExpressionIsClassifiedAsComplex(): void
    {
        // No variable, method call, or static access token appears here.
        self::assertSame(
            TokenHelper::REASON_COMPLEX,
            TokenHelper::classifyUnresolvableReason($this->tokensFor("'prefix/' . UNKNOWN_CONST"))
        );
    }

    public function testUnsupportedFunctionCallIsClassifiedAsComplex(): void
    {
        self::assertSame(
            TokenHelper::REASON_COMPLEX,
            TokenHelper::classifyUnresolvableReason($this->tokensFor('realpath(__DIR__)'))
        );
    }

    public function testEmptyTokenListIsClassifiedAsComplex(): void
    {
        self::assertSame(TokenHelper::REASON_COMPLEX, TokenHelper::classifyUnresolvableReason([]));
    }

    public function testVariableFollowedByMethodCallIsClassifiedAsVariableNotMethodCall(): void
    {
        // classifyUnresolvableReason scans tokens in order and returns on the
        // first match. Since `$obj` (T_VARIABLE) appears before `->`
        // (T_OBJECT_OPERATOR), this is classified as "variable", not
        // "method_call", even though it is also a method call expression.
        self::assertSame(
            TokenHelper::REASON_VARIABLE,
            TokenHelper::classifyUnresolvableReason($this->tokensFor('$obj->method()'))
        );
    }

    // -------------------------------------------------------------------------
    // isNameToken()
    // -------------------------------------------------------------------------

    public function testIsNameTokenReturnsTrueForTString(): void
    {
        self::assertTrue(TokenHelper::isNameToken(T_STRING));
    }

    public function testIsNameTokenReturnsTrueForNamespaceSeparator(): void
    {
        self::assertTrue(TokenHelper::isNameToken(T_NS_SEPARATOR));
    }

    public function testIsNameTokenReturnsTrueForQualifiedAndFullyQualifiedNames(): void
    {
        self::assertTrue(TokenHelper::isNameToken(T_NAME_QUALIFIED));
        self::assertTrue(TokenHelper::isNameToken(T_NAME_FULLY_QUALIFIED));
        self::assertTrue(TokenHelper::isNameToken(T_NAME_RELATIVE));
    }

    public function testIsNameTokenReturnsFalseForUnrelatedToken(): void
    {
        self::assertFalse(TokenHelper::isNameToken(T_VARIABLE));
    }

    public function testIsNameTokenReturnsFalseForNull(): void
    {
        self::assertFalse(TokenHelper::isNameToken(null));
    }

    // -------------------------------------------------------------------------
    // splitArgs()
    // -------------------------------------------------------------------------

    public function testSplitArgsSplitsOnTopLevelCommas(): void
    {
        $args = TokenHelper::splitArgs($this->tokensFor('$a, $b, $c'));

        self::assertCount(3, $args);
        self::assertSame('$a', TokenHelper::tokensToString($args[0]));
        self::assertSame('$b', TokenHelper::tokensToString($args[1]));
        self::assertSame('$c', TokenHelper::tokensToString($args[2]));
    }

    public function testSplitArgsRespectsNestedParentheses(): void
    {
        // The comma inside foo(1, 2) must not split the top-level argument list.
        $args = TokenHelper::splitArgs($this->tokensFor('foo(1, 2), $b'));

        self::assertCount(2, $args);
        self::assertSame('foo(1, 2)', TokenHelper::tokensToString($args[0]));
        self::assertSame('$b', TokenHelper::tokensToString($args[1]));
    }

    public function testSplitArgsReturnsEmptyArrayForEmptyTokenList(): void
    {
        self::assertSame([], TokenHelper::splitArgs([]));
    }

    public function testSplitArgsReturnsSingleArgumentWhenNoCommaPresent(): void
    {
        $args = TokenHelper::splitArgs($this->tokensFor('$onlyArg'));

        self::assertCount(1, $args);
        self::assertSame('$onlyArg', TokenHelper::tokensToString($args[0]));
    }

    // -------------------------------------------------------------------------
    // stripQuotes()
    // -------------------------------------------------------------------------

    public function testStripQuotesRemovesSingleQuotes(): void
    {
        self::assertSame('foo', TokenHelper::stripQuotes("'foo'"));
    }

    public function testStripQuotesRemovesDoubleQuotes(): void
    {
        self::assertSame('foo', TokenHelper::stripQuotes('"foo"'));
    }

    public function testStripQuotesUnescapesSingleQuoteEscapesInSingleQuotedString(): void
    {
        // Single-quoted strings only unescape \' and \\.
        self::assertSame("it's", TokenHelper::stripQuotes("'it\\'s'"));
        self::assertSame('back\\slash', TokenHelper::stripQuotes("'back\\\\slash'"));
    }

    public function testStripQuotesUnescapesRecognizedDoubleQuoteEscapes(): void
    {
        self::assertSame("line\nbreak", TokenHelper::stripQuotes('"line\nbreak"'));
        self::assertSame("a\tb", TokenHelper::stripQuotes('"a\tb"'));
        self::assertSame('A', TokenHelper::stripQuotes('"\x41"'));
        self::assertSame('A', TokenHelper::stripQuotes('"\101"'));
        self::assertSame("\u{1F600}", TokenHelper::stripQuotes('"\u{1F600}"'));
        self::assertSame('$v', TokenHelper::stripQuotes('"\$v"'));
    }

    /**
     * PHP keeps the backslash on escapes it does not recognize (unlike C's
     * stripcslashes, which drops it and mangles \a / \b). These are exactly the
     * Windows-style paths legacy code puts in double quotes.
     *
     * @param string $literal
     * @param string $expected
     */
    #[DataProvider('phpFaithfulDoubleQuoteCases')]
    public function testStripQuotesKeepsBackslashOnUnknownDoubleQuoteEscapes(string $literal, string $expected): void
    {
        self::assertSame($expected, TokenHelper::stripQuotes($literal));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function phpFaithfulDoubleQuoteCases(): iterable
    {
        // Expected values use PHP's own double-quoted literals as ground truth.
        yield 'unknown escape \\d' => ['"sub\dir/file.php"', "sub\dir/file.php"];
        yield 'no escape \\a' => ['"p\alpha.php"', "p\alpha.php"];
        yield 'no escape \\b' => ['"a\bin.php"', "a\bin.php"];
    }

    public function testStripQuotesHandlesBinaryStringPrefix(): void
    {
        self::assertSame('foo.php', TokenHelper::stripQuotes("b'foo.php'"));
        self::assertSame('bar.php', TokenHelper::stripQuotes('B"bar.php"'));
    }

    public function testStripQuotesReturnsNullForUnquotedString(): void
    {
        self::assertNull(TokenHelper::stripQuotes('foo'));
    }

    public function testStripQuotesReturnsNullForMismatchedQuotes(): void
    {
        self::assertNull(TokenHelper::stripQuotes('\'foo"'));
    }

    public function testStripQuotesReturnsNullForStringShorterThanTwoCharacters(): void
    {
        self::assertNull(TokenHelper::stripQuotes("'"));
        self::assertNull(TokenHelper::stripQuotes(''));
    }
}
