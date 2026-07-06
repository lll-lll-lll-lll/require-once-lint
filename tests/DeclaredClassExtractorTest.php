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
 *   - duplicate name removal
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

        self::assertSame(['App\Foo'], $this->extractor->extract($code));
    }

    public function testExtractsClassWithoutNamespace(): void
    {
        $code = <<<'PHP'
            <?php
            class Foo
            {
            }
            PHP;

        self::assertSame(['Foo'], $this->extractor->extract($code));
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

        self::assertSame(['App\Foo'], $this->extractor->extract($code));
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

        self::assertSame(['App\Foo'], $this->extractor->extract($code));
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

        self::assertSame(['App\Foo'], $this->extractor->extract($code));
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

        self::assertSame(['App\FooInterface', 'App\Foo'], $this->extractor->extract($code));
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

        self::assertSame(['App\Foo'], $this->extractor->extract($code));
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

        self::assertSame(['App\UsesClassConst'], $this->extractor->extract($code));
    }

    public function testRemovesDuplicateNames(): void
    {
        // Declaring the same class twice in one token stream is not valid PHP
        // to run, but the extractor works purely on tokens and must still
        // deduplicate rather than reporting the same FQCN twice.
        $code = <<<'PHP'
            <?php
            namespace App;

            if (!class_exists(Foo::class)) {
                class Foo
                {
                }
            }

            if (!class_exists(\App\Foo::class)) {
                class Foo
                {
                }
            }
            PHP;

        self::assertSame(['App\Foo'], $this->extractor->extract($code));
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

        self::assertSame(['App\One\Foo', 'App\Two\Bar'], $this->extractor->extract($code));
    }

    public function testReturnsEmptyArrayWhenNoDeclarationsPresent(): void
    {
        $code = <<<'PHP'
            <?php
            function helper(): void
            {
            }
            PHP;

        self::assertSame([], $this->extractor->extract($code));
    }

    // -------------------------------------------------------------------------
    // extractTopLevel (unconditional declarations only)
    // -------------------------------------------------------------------------

    public function testExtractTopLevelReturnsUnconditionalDeclarations(): void
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

    public function testExtractTopLevelSkipsGuardedDeclarationThatExtractFinds(): void
    {
        // A polyfill: the class is declared only when it is missing. extract()
        // finds it (Composer's classmap would too); extractTopLevel() does not,
        // because the require does not declare it unconditionally.
        $code = <<<'PHP'
            <?php
            namespace App;
            if (!class_exists(Widget::class)) {
                class Widget
                {
                }
            }
            PHP;

        self::assertSame(['App\Widget'], $this->extractor->extract($code));
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
}
