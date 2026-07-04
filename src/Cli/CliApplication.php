<?php

declare(strict_types=1);

namespace Depone\Internal\Cli;

use Composer\InstalledVersions;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * Entry point for the CLI application.
 *
 * stdout/stderr and the working directory are injectable for testability.
 *
 * @internal
 */
final class CliApplication
{
    /** @var resource */
    private mixed $stdout;
    /** @var resource */
    private mixed $stderr;

    /**
     * @param resource|null $stdout   Standard output stream (null = STDOUT)
     * @param resource|null $stderr   Standard error stream (null = STDERR)
     * @param string|null   $repoRoot Root directory to analyze (null = getcwd())
     */
    public function __construct(
        mixed $stdout = null,
        mixed $stderr = null,
        private ?string $repoRoot = null,
    ) {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    /**
     * Runs the CLI and returns an exit code.
     *
     * @param list<string> $argv Command-line arguments
     * @return int Exit code (0 = success, 1 = error)
     */
    public function __invoke(array $argv): int
    {
        $command = new FindRedundantCommand($this->repoRoot);

        $app = new Application('depone', InstalledVersions::getPrettyVersion('lll-lll-lll-lll/depone') ?? 'unknown');
        $app->addCommand($command);
        $app->setDefaultCommand(FindRedundantCommand::NAME, true);
        $app->setAutoExit(false);

        $input  = new ArgvInput($argv);
        $output = new DualOutput($this->stdout, $this->stderr);

        return $app->run($input, $output);
    }
}
