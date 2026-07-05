<?php

declare(strict_types=1);

namespace Depone\Tests;

use PHPUnit\Framework\TestCase;
use Depone\Internal\Resolver\ComposerAutoloadConfig;

/**
 * Unit tests for ComposerAutoloadConfig.
 *
 * Covered behavior:
 *   - psr-4/psr-0/classmap/files entries are parsed from composer.json
 *   - autoload-dev rules are merged in alongside autoload
 *   - a repo without composer.json yields an empty, non-throwing config
 *
 * Fixture: tests/Fixture/AutoloadResolverProject/, tests/Fixture/DoctorProject/
 */
final class ComposerAutoloadConfigTest extends TestCase
{
    public function testPsr4RulesAreParsedWithRepoRootApplied(): void
    {
        $root = $this->getFixturePath('AutoloadResolverProject');
        $config = new ComposerAutoloadConfig($root);

        $psr4 = $config->psr4();

        self::assertArrayHasKey('App\\', $psr4);
        self::assertSame([$root . '/src'], $psr4['App\\']);

        self::assertArrayHasKey('App\\Specific\\', $psr4);
        self::assertSame([$root . '/src/specific'], $psr4['App\\Specific\\']);

        self::assertArrayHasKey('Multi\\', $psr4);
        self::assertSame([$root . '/multi-a', $root . '/multi-b'], $psr4['Multi\\']);
    }

    public function testPsr0RulesAreParsedWithRepoRootApplied(): void
    {
        $root = $this->getFixturePath('AutoloadResolverProject');
        $config = new ComposerAutoloadConfig($root);

        self::assertSame(['Legacy_' => [$root . '/legacy']], $config->psr0());
    }

    public function testClassmapEntriesAreParsed(): void
    {
        $root = $this->getFixturePath('AutoloadResolverProject');
        $config = new ComposerAutoloadConfig($root);

        self::assertSame([$root . '/classmap/'], $config->classmapEntries());
    }

    public function testAutoloadDevRulesAreMergedIn(): void
    {
        $root = $this->getFixturePath('AutoloadResolverProject');
        $config = new ComposerAutoloadConfig($root);

        self::assertArrayHasKey('App\\Tests\\', $config->psr4());
        self::assertSame([$root . '/dev-tests'], $config->psr4()['App\\Tests\\']);
    }

    public function testFilesEntriesAreParsed(): void
    {
        $root = $this->getFixturePath('DoctorProject');
        $config = new ComposerAutoloadConfig($root);

        self::assertSame([$root . '/src/bootstrap.php'], $config->filesEntries());
    }

    public function testMissingComposerJsonYieldsEmptyConfigWithoutThrowing(): void
    {
        $tmpDir = sys_get_temp_dir() . '/composer_autoload_config_test_' . uniqid('', true);
        mkdir($tmpDir);
        try {
            $config = new ComposerAutoloadConfig($tmpDir);

            self::assertSame([], $config->psr4());
            self::assertSame([], $config->psr0());
            self::assertSame([], $config->classmapEntries());
            self::assertSame([], $config->filesEntries());
        } finally {
            rmdir($tmpDir);
        }
    }

    private function getFixturePath(string $name): string
    {
        $path = realpath(__DIR__ . '/Fixture/' . $name);
        self::assertNotFalse($path);

        return $path;
    }
}
