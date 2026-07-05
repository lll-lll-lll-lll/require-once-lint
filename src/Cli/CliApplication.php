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
        $app->addCommand(new DoctorCommand($this->repoRoot));
        $app->setDefaultCommand(FindRedundantCommand::NAME, false);
        $app->setAutoExit(false);

        $input  = new ArgvInput($this->routeToDefaultCommand($app, $argv));
        $output = new DualOutput($this->stdout, $this->stderr);

        return $app->run($input, $output);
    }

    /**
     * With subcommands registered, `setDefaultCommand(..., false)` is required so
     * they are reachable, but it means Symfony can no longer tell an option's
     * value (e.g. `--trace src/Bar.php`) apart from a command name when the
     * default command is invoked implicitly.
     *
     * Resolve this by looking at the first non-option token: if it is a known
     * command name, leave argv untouched so the Application dispatches that
     * command with any preceding global options (`-q doctor`, `--help doctor`)
     * still applied. Otherwise prepend the default command name, so a bare
     * invocation — or a default-command option value such as `--trace <path>`
     * that is not a command — is handled by the default command.
     *
     * @param list<string> $argv
     * @return list<string>
     */
    private function routeToDefaultCommand(Application $app, array $argv): array
    {
        foreach (array_slice($argv, 1) as $token) {
            if ($token === '--') {
                break;
            }
            if ($token !== '' && $token[0] !== '-') {
                if ($app->has($token)) {
                    return $argv;
                }
                break;
            }
        }

        return [$argv[0], FindRedundantCommand::NAME, ...array_slice($argv, 1)];
    }
}
