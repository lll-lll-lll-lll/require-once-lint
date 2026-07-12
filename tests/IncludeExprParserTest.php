<?php

declare(strict_types=1);

namespace Depone\Tests;

use PHPUnit\Framework\TestCase;
use PhpToken;
use Depone\Internal\Tokenizer\IncludeExprParser;
use Depone\Internal\Tokenizer\TokenHelper;

/**
 * Unit tests for IncludeExprParser's static expression evaluation.
 *
 * Covered behavior:
 *   - string literals (single and double quoted)
 *   - string concatenation (.)
 *   - magic constants (__DIR__, __FILE__)
 *   - user-defined constants ($consts)
 *   - dirname() calls (with and without levels)
 *   - parenthesized grouping
 *   - define() argument extraction
 *   - unresolvable cases (variables, unknown constants, incomplete expressions, etc.)
 */
final class IncludeExprParserTest extends TestCase
{
    /** Fake file path used in tests. */
    private const FILE = '/project/src/Foo.php';

    private IncludeExprParser $parser;

    protected function setUp(): void
    {
        $this->parser = new IncludeExprParser();
    }

    /**
     * Tokenizes a PHP expression string and evaluates it with evalStaticExpr().
     *
     * @param array<string, string> $consts
     */
    private function parse(string $phpExpr, array $consts = [], string $file = self::FILE): ?string
    {
        return $this->parser->evalStaticExpr($this->tokensFor($phpExpr), $consts, $file);
    }

    /**
     * @return list<PhpToken>
     */
    private function tokensFor(string $phpExpr): array
    {
        $tokens = PhpToken::tokenize('<?php ' . $phpExpr);

        // Remove the leading T_OPEN_TAG token.
        return array_slice($tokens, 1);
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

    public function testConcatenationWithIntegerLiteral(): void
    {
        // php-parser's evaluator applies PHP's own concatenation semantics,
        // so numeric literals concatenate the way they would at runtime.
        self::assertSame('v1/api.php', $this->parse("'v' . 1 . '/api.php'"));
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

    public function testOtherMagicConstantsAreUnresolvable(): void
    {
        // Only __DIR__ and __FILE__ carry path context; __LINE__ etc. do not.
        self::assertNull($this->parse("__LINE__ . '/x.php'"));
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

    public function testQualifiedConstantIsUnresolvable(): void
    {
        // Only unqualified names are matched against collected define()s.
        $consts = ['LIB_DIR' => '/var/www/inc'];
        self::assertNull($this->parse('Config\LIB_DIR', $consts));
    }

    public function testConstantShadowingFunctionNameResolvesLikePhp(): void
    {
        // Constants and functions live in separate namespaces in PHP, so a
        // define()'d constant named like a function resolves as a constant —
        // exactly what the engine would do with a bare `dirname` fetch.
        $consts = ['dirname' => '/from/const'];
        self::assertSame('/from/const', $this->parse('dirname', $consts));
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

    public function testFullyQualifiedDirnameResolves(): void
    {
        // \dirname() names the same global function.
        self::assertSame('/project/src', $this->parse('\dirname(__FILE__)'));
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
        // A negative level is not an integer literal but a unary minus
        // expression, and dirname() itself rejects levels < 1.
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

    public function testDirnameWithNoArgumentsReturnsNull(): void
    {
        // dirname() with no arguments returns null.
        self::assertNull($this->parse('dirname()'));
    }

    public function testDirnameWithNonLnumberLevelReturnsNull(): void
    {
        // A string level is not an integer literal, so the expression returns null.
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
        // () contains no expression.
        self::assertNull($this->parse('()'));
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

    public function testInterpolatedStringReturnsNull(): void
    {
        self::assertNull($this->parse('"$dir/x.php"'));
    }

    public function testUnknownFunctionReturnsNull(): void
    {
        // Function calls other than dirname() are not supported.
        self::assertNull($this->parse('realpath(__DIR__)'));
    }

    public function testNonStringResultReturnsNull(): void
    {
        // The expression evaluates, but a path must be a string.
        self::assertNull($this->parse('true'));
    }

    public function testTrailingTokensReturnNull(): void
    {
        // Extra trailing tokens mean the snippet is not a single expression.
        // Example: 'foo' 'bar' (two literals with no concatenation operator)
        self::assertNull($this->parse("'foo' 'bar'"));
    }

    public function testEmptyTokensReturnNull(): void
    {
        self::assertNull($this->parser->evalStaticExpr([], [], self::FILE));
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

    // -------------------------------------------------------------------------
    // parseDefine()
    // -------------------------------------------------------------------------

    /**
     * Runs parseDefine() against source whose first define starts at the
     * given token, mirroring how the analyzer dispatches on T_STRING 'define'.
     *
     * @param array<string, string> $consts
     * @return array{0: string, 1: string}|null
     */
    private function define(string $php, array $consts = []): ?array
    {
        $tokens = PhpToken::tokenize('<?php ' . $php);
        foreach ($tokens as $i => $token) {
            if ($token->id === T_STRING && strtolower($token->text) === 'define') {
                return $this->parser->parseDefine($tokens, $i, $consts, self::FILE);
            }
        }
        self::fail('no define token found');
    }

    public function testParseDefineExtractsNameAndValue(): void
    {
        self::assertSame(['APP_ROOT', '/app'], $this->define("define('APP_ROOT', '/app');"));
    }

    public function testParseDefineEvaluatesValueExpression(): void
    {
        self::assertSame(
            ['LIB_DIR', '/project/src/lib'],
            $this->define("define('LIB_DIR', __DIR__ . '/lib');")
        );
    }

    public function testParseDefineResolvesEarlierConstants(): void
    {
        self::assertSame(
            ['MOD_DIR', '/base/mod'],
            $this->define("define('MOD_DIR', BASE . '/mod');", ['BASE' => '/base'])
        );
    }

    public function testParseDefineWithNonLiteralNameReturnsNull(): void
    {
        self::assertNull($this->define('define($name, "/app");'));
    }

    public function testParseDefineWithUnresolvableValueReturnsNull(): void
    {
        self::assertNull($this->define("define('APP_ROOT', \$dir);"));
    }

    public function testParseDefineWithSingleArgumentReturnsNull(): void
    {
        self::assertNull($this->define("define('APP_ROOT');"));
    }

    public function testParseDefineWithNonStringValueReturnsNull(): void
    {
        // Only string constants can take part in path resolution.
        self::assertNull($this->define("define('LEVEL', 3);"));
    }

    // -------------------------------------------------------------------------
    // classifyUnresolvableReason() (AST-based)
    // -------------------------------------------------------------------------

    public function testClassifyVariable(): void
    {
        self::assertSame(
            TokenHelper::REASON_VARIABLE,
            $this->parser->classifyUnresolvableReason($this->tokensFor("__DIR__ . '/' . \$file"))
        );
    }

    public function testClassifyMethodCallOnFunctionResult(): void
    {
        self::assertSame(
            TokenHelper::REASON_METHOD_CALL,
            $this->parser->classifyUnresolvableReason($this->tokensFor('getConfig()->path()'))
        );
    }

    public function testClassifyNullsafeMethodCallAsMethodCall(): void
    {
        // `?->` is T_NULLSAFE_OBJECT_OPERATOR, which the old token scan never
        // matched (misclassified as complex); the AST sees the call itself.
        self::assertSame(
            TokenHelper::REASON_METHOD_CALL,
            $this->parser->classifyUnresolvableReason($this->tokensFor('getConfig()?->path()'))
        );
    }

    public function testClassifyStaticAccess(): void
    {
        self::assertSame(
            TokenHelper::REASON_STATIC_ACCESS,
            $this->parser->classifyUnresolvableReason($this->tokensFor('Loader::PATH'))
        );
    }

    public function testClassifyVariableReceiverWinsOverItsMethodCall(): void
    {
        // $obj->method() is both a variable and a method call; the variable
        // is the earliest (and more precise) blocker, matching the old
        // left-to-right token scan.
        self::assertSame(
            TokenHelper::REASON_VARIABLE,
            $this->parser->classifyUnresolvableReason($this->tokensFor('$obj->method()'))
        );
    }

    public function testClassifyEarliestMarkerWinsInSourceOrder(): void
    {
        // The static access starts before the variable, so it is the reason —
        // same outcome as scanning tokens left to right.
        self::assertSame(
            TokenHelper::REASON_STATIC_ACCESS,
            $this->parser->classifyUnresolvableReason($this->tokensFor("Loader::path(\$name) . '/x.php'"))
        );
    }

    public function testClassifyUnresolvableConstantAsComplex(): void
    {
        self::assertSame(
            TokenHelper::REASON_COMPLEX,
            $this->parser->classifyUnresolvableReason($this->tokensFor("'prefix/' . UNKNOWN_CONST"))
        );
    }

    public function testClassifyUnparsableSnippetFallsBackToTokenScan(): void
    {
        // An unclosed paren cannot be parsed; the raw-token fallback still
        // spots the variable.
        self::assertSame(
            TokenHelper::REASON_VARIABLE,
            $this->parser->classifyUnresolvableReason($this->tokensFor("('foo' . \$dir"))
        );
    }
}
