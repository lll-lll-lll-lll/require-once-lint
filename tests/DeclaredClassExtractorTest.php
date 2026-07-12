<?php

declare(strict_types=1);

namespace Depone\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Depone\Internal\Tokenizer\DeclaredClassExtractor;

/**
 * Unit tests for DeclaredClassExtractor.
 *
 * Covered behavior:
 *   - class / interface / trait / enum declarations
 *   - namespaced and global (no-namespace) declarations
 *   - fully qualified name assembly
 *   - anonymous class exclusion
 *   - class-name constant (`Foo::class`) exclusion
 *   - guarded (conditional) declarations excluded from the top-level view
 *   - declaresOnlyTypes: PSR-1 side-effect detection (pure declaration files vs
 *     files that also declare functions/constants or run top-level statements)
 */
final class DeclaredClassExtractorTest extends TestCase
{
    private DeclaredClassExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new DeclaredClassExtractor();
    }

    public function testExtractsNamespacedClass(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App;
            class Foo
            {
            }
            PHP;

        self::assertSame(['App\Foo'], $this->extractor->extractTopLevel($code));
    }

    public function testExtractsClassWithoutNamespace(): void
    {
        $code = <<<'PHP'
            <?php
            class Foo
            {
            }
            PHP;

        self::assertSame(['Foo'], $this->extractor->extractTopLevel($code));
    }

    public function testExtractsInterface(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App;
            interface Foo
            {
            }
            PHP;

        self::assertSame(['App\Foo'], $this->extractor->extractTopLevel($code));
    }

    public function testExtractsTrait(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App;
            trait Foo
            {
            }
            PHP;

        self::assertSame(['App\Foo'], $this->extractor->extractTopLevel($code));
    }

    public function testExtractsEnum(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App;
            enum Foo
            {
            }
            PHP;

        self::assertSame(['App\Foo'], $this->extractor->extractTopLevel($code));
    }

    public function testExtractsMultipleDeclarationsInOneFile(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App;

            interface FooInterface
            {
            }

            class Foo implements FooInterface
            {
            }
            PHP;

        self::assertSame(['App\FooInterface', 'App\Foo'], $this->extractor->extractTopLevel($code));
    }

    public function testExcludesAnonymousClass(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App;

            class Foo
            {
                public function make(): object
                {
                    return new class {
                    };
                }
            }
            PHP;

        self::assertSame(['App\Foo'], $this->extractor->extractTopLevel($code));
    }

    public function testExcludesClassNameConstant(): void
    {
        // `Foo::class` is a class-name constant, not a declaration. The `class`
        // keyword must not be treated as one and grab the following identifier
        // (here the method name `other`) as a phantom class.
        $code = <<<'PHP'
            <?php
            namespace App;

            class UsesClassConst
            {
                public function run(): string
                {
                    return UsesClassConst::class;
                }

                public function other(): int
                {
                    return 1;
                }
            }
            PHP;

        self::assertSame(['App\UsesClassConst'], $this->extractor->extractTopLevel($code));
    }

    public function testNamespaceResetsBetweenBlocks(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App\One;
            class Foo
            {
            }

            namespace App\Two;
            class Bar
            {
            }
            PHP;

        self::assertSame(['App\One\Foo', 'App\Two\Bar'], $this->extractor->extractTopLevel($code));
    }

    public function testReturnsEmptyArrayWhenNoDeclarationsPresent(): void
    {
        $code = <<<'PHP'
            <?php
            function helper(): void
            {
            }
            PHP;

        self::assertSame([], $this->extractor->extractTopLevel($code));
    }

    // -------------------------------------------------------------------------
    // extractTopLevel (unconditional declarations only)
    // -------------------------------------------------------------------------

    public function testExtractTopLevelSkipsGuardedDeclaration(): void
    {
        // A polyfill: the class is declared only when it is missing. Composer's
        // classmap generator finds it (the guard does not keep it out of the
        // classmap), but extractTopLevel() does not, because the require does
        // not declare it unconditionally.
        $code = <<<'PHP'
            <?php
            namespace App;
            if (!class_exists(Widget::class)) {
                class Widget
                {
                }
            }
            PHP;

        self::assertSame([], $this->extractor->extractTopLevel($code));
    }

    public function testExtractTopLevelSkipsClassNestedInFunction(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App;
            function make(): object
            {
                return new class {
                };
            }
            class Real
            {
            }
            PHP;

        self::assertSame(['App\Real'], $this->extractor->extractTopLevel($code));
    }

    // -------------------------------------------------------------------------
    // declaresOnlyTypes (PSR-1 side-effect gate)
    // -------------------------------------------------------------------------

    #[DataProvider('pureDeclarationFiles')]
    public function testDeclaresOnlyTypesAcceptsPureDeclarationFiles(string $code): void
    {
        self::assertTrue($this->extractor->declaresOnlyTypes($code));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pureDeclarationFiles(): iterable
    {
        yield 'plain class' => ['<?php namespace App; class Foo {}'];
        yield 'interface' => ['<?php interface I {}'];
        yield 'trait and enum' => ['<?php trait T {} enum E { case A; }'];
        yield 'declare then class' => ["<?php\ndeclare(strict_types=1);\nnamespace App;\nclass Foo {}"];
        yield 'use imports (incl. use function) then class' => [
            "<?php\nnamespace App;\nuse Other\\Thing;\nuse function Other\\f;\nclass Foo {}",
        ];
        yield 'attribute before class' => ["<?php\nnamespace App;\n#[\\Attribute]\nclass Foo {}"];
        yield 'braced namespaces, all declarations' => [
            '<?php namespace App { class Foo {} } namespace { class Bar {} }',
        ];
        yield 'abstract/final/readonly modifiers' => [
            '<?php abstract class A {} final class B {} readonly class C {}',
        ];
        yield 'method bodies with statements and closures are ignored' => [
            "<?php\nclass Foo {\n use SomeTrait;\n public function x(){ \$f = function () use (\$y) { return 1; }; return \$f; }\n}",
        ];
        yield 'deeply nested blocks inside a method body' => [
            "<?php\nclass Foo {\n public function x(){ if (true) { for (\$i = 0; \$i < 1; \$i++) { echo \$i; } } }\n}",
        ];
        yield 'two class declarations back to back' => ['<?php class A {} class B {}'];
    }

    #[DataProvider('filesWithSideEffects')]
    public function testDeclaresOnlyTypesRejectsFilesWithSideEffects(string $code): void
    {
        self::assertFalse($this->extractor->declaresOnlyTypes($code));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function filesWithSideEffects(): iterable
    {
        yield 'class plus function' => ['<?php namespace App; class Foo {} function bar() { return 1; }'];
        yield 'class plus const' => ['<?php namespace App; const X = 1; class Foo {}'];
        yield 'class plus define()' => ["<?php\nnamespace App;\ndefine('X', 1);\nclass Foo {}"];
        yield 'class plus top-level statement' => ["<?php\nnamespace App;\n\$GLOBALS['x'] = 1;\nclass Foo {}"];
        yield 'class plus echo' => ['<?php class Foo {} echo "hi";'];
        yield 'class plus nested require' => ['<?php class Foo {} require "x.php";'];
        yield 'function inside a braced namespace' => ['<?php namespace App { class Foo {} } namespace { function g() {} }'];
        yield 'class defined inside a top-level if' => ['<?php if (true) { class Foo {} }'];
        yield 'anonymous class expression' => ['<?php $x = new class {};'];
        yield 'config-style return' => ['<?php return [1, 2, 3];'];
    }

    // -------------------------------------------------------------------------
    // profile (side-effect inventory)
    // -------------------------------------------------------------------------

    #[DataProvider('sideEffectKinds')]
    public function testProfileClassifiesSideEffectKinds(string $code, string $expectedKind): void
    {
        $profile = $this->extractor->profile($code);

        self::assertTrue($profile->parsed);
        self::assertCount(1, $profile->sideEffects);
        self::assertSame($expectedKind, $profile->sideEffects[0]['kind']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sideEffectKinds(): iterable
    {
        yield 'function definition' => ['<?php function f() {}', 'function'];
        yield 'const statement' => ['<?php const X = 1;', 'constant'];
        yield 'define() call' => ["<?php define('X', 1);", 'define'];
        yield 'fully qualified \define() call' => ["<?php \\define('X', 1);", 'define'];
        yield 'ini_set() call' => ["<?php ini_set('memory_limit', '1G');", 'ini_set'];
        yield 'other function call' => ['<?php session_start();', 'call'];
        yield 'static method call' => ['<?php App\Kernel::boot();', 'call'];
        yield 'assignment' => ['<?php $x = 1;', 'assignment'];
        yield 'global statement' => ['<?php global $db;', 'assignment'];
        yield 'nested require' => ["<?php require 'x.php';", 'include'];
        yield 'top-level if' => ['<?php if (PHP_SAPI === "cli") { echo 1; }', 'control_flow'];
        yield 'config-style return' => ['<?php return [1];', 'return'];
        yield 'echo' => ['<?php echo "hi";', 'output'];
        yield 'inline HTML' => ['hello<?php class Foo {}', 'output'];
        yield 'unset statement' => ['<?php unset($GLOBALS["x"]);', 'other'];
    }

    public function testProfileRecordsLineAndCommentFreeExcerpt(): void
    {
        // The excerpt is php-parser's own rendering of the statement — one
        // line, without the comment attached above it — and the line number
        // is the statement's, not the comment's.
        $code = <<<'PHP'
            <?php

            // Bootstraps the legacy constants.
            define(
                'APP_ROOT',
                __DIR__
            );
            PHP;

        $profile = $this->extractor->profile($code);

        self::assertSame(
            [['kind' => 'define', 'line' => 4, 'excerpt' => "define('APP_ROOT', __DIR__);"]],
            $profile->sideEffects
        );
    }

    public function testProfileTruncatesLongExcerpts(): void
    {
        $long = str_repeat('a', 200);

        $profile = $this->extractor->profile("<?php echo '{$long}';");

        $excerpt = $profile->sideEffects[0]['excerpt'];
        self::assertStringEndsWith('…', $excerpt);
        self::assertSame(80 + strlen('…'), strlen($excerpt));
    }

    public function testProfileOfUnparsableSourceCountsAsSideEffect(): void
    {
        $profile = $this->extractor->profile("<?php\nclass {");

        self::assertFalse($profile->parsed);
        self::assertFalse($profile->declaresOnlyTypes());
        self::assertSame([], $profile->declaredClasses);
        self::assertCount(1, $profile->sideEffects);
        self::assertSame('unparsable', $profile->sideEffects[0]['kind']);
        self::assertSame(2, $profile->sideEffects[0]['line']);
    }

    public function testProfileSeparatesGuardedDeclarationsFromTopLevel(): void
    {
        // A polyfill: extract()'s classmap view sees the guarded class, the
        // top-level view does not, and the guard itself is the side effect.
        $code = <<<'PHP'
            <?php
            namespace App;
            if (!class_exists(Widget::class)) {
                class Widget
                {
                }
            }
            PHP;

        $profile = $this->extractor->profile($code);

        self::assertSame(['App\Widget'], $profile->declaredClasses);
        self::assertSame([], $profile->topLevelClasses);
        self::assertCount(1, $profile->sideEffects);
        self::assertSame('control_flow', $profile->sideEffects[0]['kind']);
    }

    public function testProfileOfPureDeclarationFileHasNoSideEffects(): void
    {
        $code = '<?php declare(strict_types=1); namespace App; use Other\Thing; class Foo {}';

        $profile = $this->extractor->profile($code);

        self::assertTrue($profile->declaresOnlyTypes());
        self::assertSame(['App\Foo'], $profile->topLevelClasses);
        self::assertSame(['App\Foo'], $profile->declaredClasses);
        self::assertSame([], $profile->sideEffects);
    }
}
