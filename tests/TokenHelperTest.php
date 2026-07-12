<?php

declare(strict_types=1);

namespace Depone\Tests;

use PHPUnit\Framework\TestCase;
use PhpToken;
use Depone\Internal\Tokenizer\TokenHelper;

/**
 * Unit tests for TokenHelper::classifyUnresolvableReason(), the raw-token
 * fallback used when a snippet cannot be parsed (parseable expressions are
 * classified from the AST by IncludeExprParser).
 *
 * Covered behavior:
 *   - variable / method_call / static_access / complex classification
 *   - classification scans tokens left-to-right and returns on the first
 *     match, so a variable appearing before a later `->` or `::` wins
 */
final class TokenHelperTest extends TestCase
{
    /**
     * @return list<PhpToken>
     */
    private function tokensFor(string $phpExpr): array
    {
        $tokens = PhpToken::tokenize('<?php ' . $phpExpr);
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

    public function testNullsafeMethodCallIsClassifiedAsMethodCall(): void
    {
        // `?->` is its own token (T_NULLSAFE_OBJECT_OPERATOR), not
        // T_OBJECT_OPERATOR; it must classify as method_call all the same.
        self::assertSame(
            TokenHelper::REASON_METHOD_CALL,
            TokenHelper::classifyUnresolvableReason($this->tokensFor('getConfig()?->path()'))
        );
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
}
