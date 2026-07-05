<?php

declare(strict_types=1);

namespace Depone\Tests;

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
}
