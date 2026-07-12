<?php

declare(strict_types=1);

namespace Depone\Internal\Cli;

use Depone\Internal\Core\Analyzer;
use Depone\Internal\Core\DependencyGraph;
use Depone\Internal\Core\OutputFormatter as InternalOutputFormatter;
use Depone\Internal\Exception\AnalyzerException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
final class FindRedundantCommand extends Command
{
    public const NAME = 'depone';

    /** Analysis ran; no redundant or conflicting require was found. */
    public const EXIT_OK = 0;
    /** Analysis ran; at least one redundant or conflicting require was reported. */
    public const EXIT_FINDINGS = 1;
    /** The analysis could not run (unreadable composer.json, invalid invocation, ...). */
    public const EXIT_ERROR = 2;

    private const MAX_PATHS = 20;
    private const MAX_DEPTH = 25;

    private const FORMAT_TEXT = 'text';
    private const FORMAT_JSON = 'json';

    public function __construct(private ?string $repoRoot = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Classify require_once statements by their relationship to Composer autoload (redundant, conflicting).')
            ->addOption('trace', null, InputOption::VALUE_REQUIRED, 'Show reverse caller traces for the given file path (repo relative) — who requires this file?')
            ->addOption('inventory', null, InputOption::VALUE_NONE, 'List the kept (load-bearing) require_once targets and why they cannot be removed: side effects, unreachable classes, ...')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text (default) or json.', self::FORMAT_TEXT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $repoRoot = $this->repoRoot ?? getcwd();
        if ($repoRoot === false) {
            $errOutput->writeln('failed to resolve current working directory');
            return self::EXIT_ERROR;
        }

        $traceOption = $input->getOption('trace');
        $traceTarget = is_string($traceOption) ? $traceOption : null;

        $inventory = (bool) $input->getOption('inventory');
        if ($inventory && $traceTarget !== null) {
            $errOutput->writeln('options --trace and --inventory cannot be combined');
            return self::EXIT_ERROR;
        }

        $formatOption = $input->getOption('format');
        $format = is_string($formatOption) ? $formatOption : self::FORMAT_TEXT;
        if (!in_array($format, [self::FORMAT_TEXT, self::FORMAT_JSON], true)) {
            $errOutput->writeln("Unknown format: {$format} (expected 'text' or 'json')");
            return self::EXIT_ERROR;
        }
        $asJson = $format === self::FORMAT_JSON;

        try {
            $analyzer = new Analyzer($repoRoot);
            $result = $analyzer->run();

            $formatter = new InternalOutputFormatter();
            if ($traceTarget !== null) {
                // Trace output is informational and never fails the build.
                $graph = new DependencyGraph($result['edges'], $repoRoot);
                $trace = $graph->buildReverseTrace($traceTarget, self::MAX_PATHS, self::MAX_DEPTH);
                $this->writeRaw($output, $asJson
                    ? $formatter->formatReverseTraceJson($trace)
                    : $formatter->formatReverseTrace($trace));
                return self::EXIT_OK;
            }

            if ($inventory) {
                // The inventory is informational, like --trace: it reports why
                // the kept requires are load-bearing and never fails the build.
                $this->writeRaw($output, $asJson
                    ? $formatter->formatInventoryJson($result)
                    : $formatter->formatInventory($result));
                return self::EXIT_OK;
            }

            $this->writeRaw($output, $asJson
                ? $formatter->formatSummaryJson($result)
                : $formatter->formatSummary($result));

            // unresolved entries are reported but deliberately do not affect
            // the exit code: legacy dynamic includes are often legitimate, and
            // failing on them would make the first run red on almost every
            // legacy project.
            $hasFindings = false;
            foreach (Analyzer::ACTIONABLE_CATEGORIES as $category) {
                if ($result[$category] !== []) {
                    $hasFindings = true;
                    break;
                }
            }

            return $hasFindings ? self::EXIT_FINDINGS : self::EXIT_OK;
        } catch (AnalyzerException $e) {
            $errOutput->writeln($e->getMessage());
            return self::EXIT_ERROR;
        }
    }

    /**
     * Writes pre-formatted content verbatim, bypassing Console formatting.
     */
    private function writeRaw(OutputInterface $output, string $content): void
    {
        $output->write($content, false, OutputInterface::OUTPUT_RAW);
    }
}
