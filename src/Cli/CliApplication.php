<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Cli;

use RedundantRequireOnce\Core\Analyzer;
use RedundantRequireOnce\Exception\AnalyzerException;
use RedundantRequireOnce\Core\DependencyGraph;
use RedundantRequireOnce\Core\OutputFormatter;
use RedundantRequireOnce\Exception\CliOptionParseException;

/**
 * Main entry point for the CLI application.
 *
 * stdout/stderr and the working directory are injectable for testability.
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
     * @param array $argv Command-line arguments
     * @return int Exit code (0 = success, 1 = error)
     */
    public function __invoke(array $argv): int
    {
        try {
            $options = (new CliOptionParser())->parse($argv);
        } catch (CliOptionParseException $e) {
            fwrite($this->stderr, $e->getMessage() . "\n");
            return 1;
        }

        if ($options->help) {
            fwrite($this->stdout, CliOptionParser::getHelpMessage());
            return 0;
        }

        $repoRoot = $this->repoRoot ?? getcwd();
        if ($repoRoot === false) {
            fwrite($this->stderr, "failed to resolve current working directory\n");
            return 1;
        }

        try {
            $analyzer = new Analyzer($repoRoot);
            if ($options->consts !== []) {
                $analyzer->withGlobalConsts($options->consts);
            }
            if ($options->trace !== null || $options->deps !== null) {
                $analyzer->enableAutoloadEdges();
            }
            $result = $analyzer->run();
            if (!$options->includeNonAutoload) {
                unset($result['nonAutoloadRequireOnce']);
            }
        } catch (AnalyzerException $e) {
            fwrite($this->stderr, $e->getMessage() . "\n");
            return 1;
        }

        $graph = new DependencyGraph($result['edges'], $repoRoot);

        if ($options->trace !== null) {
            $result['trace'] = $graph->buildReverseTrace(
                $options->trace,
                $options->maxPaths,
                $options->maxDepth
            );
        }

        if ($options->deps !== null) {
            $result['deps'] = $graph->buildForwardTrace(
                $options->deps,
                $options->maxPaths,
                $options->maxDepth
            );
        }

        $formatter = new OutputFormatter();

        if ($options->json) {
            if ($options->trace !== null || $options->deps !== null) {
                $output = [];
                if ($options->trace !== null) {
                    $output['trace'] = $result['trace'];
                }
                if ($options->deps !== null) {
                    $output['deps'] = $result['deps'];
                }
                fwrite($this->stdout, $formatter->outputJson($output));
            } else {
                fwrite($this->stdout, $formatter->outputJson($result));
            }
            return 0;
        }

        if ($options->trace !== null || $options->deps !== null) {
            if ($options->trace !== null) {
                fwrite($this->stdout, $formatter->formatReverseTrace($result['trace']));
            }
            if ($options->deps !== null) {
                if ($options->trace !== null) {
                    fwrite($this->stdout, PHP_EOL);
                }
                fwrite($this->stdout, $formatter->formatForwardTrace($result['deps']));
            }
            return 0;
        }

        fwrite($this->stdout, $formatter->formatSummary($result));
        return 0;
    }
}
