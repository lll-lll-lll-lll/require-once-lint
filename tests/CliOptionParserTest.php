<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Tests;

use PHPUnit\Framework\TestCase;
use RedundantRequireOnce\Cli\CliOptionParser;
use RedundantRequireOnce\Cli\CliOptions;
use RedundantRequireOnce\Exception\CliOptionParseException;

final class CliOptionParserTest extends TestCase
{
    private function parse(string ...$args): CliOptions
    {
        return (new CliOptionParser())->parse(['bin', ...$args]);
    }

    // -------------------------------------------------------------------------
    // --define
    // -------------------------------------------------------------------------

    public function testDefineSpaceSeparated(): void
    {
        $opts = $this->parse('--define', 'FOO=/var/www/foo/');
        self::assertSame(['FOO' => '/var/www/foo/'], $opts->consts);
    }

    public function testDefineEqualsSeparated(): void
    {
        $opts = $this->parse('--define=BAR=/tmp/bar/');
        self::assertSame(['BAR' => '/tmp/bar/'], $opts->consts);
    }

    public function testDefineMultipleTimes(): void
    {
        $opts = $this->parse('--define', 'A=1', '--define', 'B=2', '--define=C=3');
        self::assertSame(['A' => '1', 'B' => '2', 'C' => '3'], $opts->consts);
    }

    public function testDefineValueWithEquals(): void
    {
        // When the value contains '=', split only at the first '='.
        $opts = $this->parse('--define', 'EXPR=a=b');
        self::assertSame(['EXPR' => 'a=b'], $opts->consts);
    }

    public function testDefineValueCanBeEmpty(): void
    {
        // Empty values such as NAME= are allowed.
        $opts = $this->parse('--define', 'EMPTY=');
        self::assertSame(['EMPTY' => ''], $opts->consts);
    }

    public function testDefineMissingValueThrows(): void
    {
        $this->expectException(CliOptionParseException::class);
        $this->parse('--define');
    }

    public function testDefineInvalidFormatNoEqualsThrows(): void
    {
        // NAME without '=' is invalid.
        $this->expectException(CliOptionParseException::class);
        $this->parse('--define', 'NOEQUALS');
    }

    public function testDefineInvalidFormatLeadingEqualsThrows(): void
    {
        // =VALUE is invalid because the constant name is empty.
        $this->expectException(CliOptionParseException::class);
        $this->parse('--define', '=VALUE');
    }

    public function testDefineEqualsSeparatedEmptyValueThrows(): void
    {
        // --define= is invalid because the whole assignment is empty.
        $this->expectException(CliOptionParseException::class);
        $this->parse('--define=');
    }

    public function testDefaultConstsIsEmptyArray(): void
    {
        $opts = $this->parse();
        self::assertSame([], $opts->consts);
    }

    // -------------------------------------------------------------------------
    // Compatibility with existing options
    // -------------------------------------------------------------------------

    public function testDefineCanBeCombinedWithOtherOptions(): void
    {
        $opts = $this->parse('--json', '--define', 'DIR=/path/', '--max-paths', '5');
        self::assertTrue($opts->json);
        self::assertSame(['DIR' => '/path/'], $opts->consts);
        self::assertSame(5, $opts->maxPaths);
    }
}
