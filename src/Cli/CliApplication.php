<?php

declare(strict_types=1);

namespace Depone\Internal\Cli;

use Composer\InstalledVersions;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
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
     * @return int Exit code (0 = no findings, 1 = findings reported,
     *             2 = the analysis could not run)
     */
    public function __invoke(array $argv): int
    {
        try {
            // Returns null (not a throw) when the package is installed but
            // replaced/provided; the throw happens when the name is absent
            // entirely — a renamed fork or a non-Composer vendored copy.
            $version = InstalledVersions::getPrettyVersion('depone/depone') ?? 'unknown';
        } catch (\OutOfBoundsException) {
            $version = 'unknown';
        }

        $app = new Application('depone', $version);
        // Registered via a command loader rather than add()/addCommand():
        // add() was removed in symfony/console 8, addCommand() only exists
        // since 7.4, and this loader API is identical across 6.4-8.
        $app->setCommandLoader(new FactoryCommandLoader([
            FindRedundantCommand::NAME => fn (): FindRedundantCommand => new FindRedundantCommand($this->repoRoot),
        ]));
        $app->setDefaultCommand(FindRedundantCommand::NAME, true);
        $app->setAutoExit(false);
        $app->setCatchExceptions(false);

        $input  = new ArgvInput($argv);
        $output = new DualOutput($this->stdout, $this->stderr);

        try {
            return $app->run($input, $output);
        } catch (\Throwable $e) {
            // Invalid invocation (unknown option, ...) or an unexpected
            // failure: both mean the analysis did not run, which is exit
            // code 2 — distinct from exit code 1, "analysis ran and found
            // something".
            $app->renderThrowable($e, $output->getErrorOutput());

            return FindRedundantCommand::EXIT_ERROR;
        }
    }
}
