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

    private const MAX_PATHS = 20;
    private const MAX_DEPTH = 25;

    public function __construct(private ?string $repoRoot = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Detect redundant require_once statements for files already covered by Composer autoload.')
            ->addOption('trace', null, InputOption::VALUE_REQUIRED, 'Show reverse caller traces for the given file path (repo relative) — who requires this file?');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $repoRoot = $this->repoRoot ?? getcwd();
        if ($repoRoot === false) {
            $errOutput->writeln('failed to resolve current working directory');
            return Command::FAILURE;
        }

        $traceOption = $input->getOption('trace');
        $traceTarget = is_string($traceOption) ? $traceOption : null;

        try {
            $analyzer = new Analyzer($repoRoot);
            $result = $analyzer->run();

            $formatter = new InternalOutputFormatter();
            if ($traceTarget !== null) {
                $graph = new DependencyGraph($result['edges'], $repoRoot);
                $trace = $graph->buildReverseTrace($traceTarget, self::MAX_PATHS, self::MAX_DEPTH);
                $this->writeRaw($output, $formatter->formatReverseTrace($trace));
            } else {
                $this->writeRaw($output, $formatter->formatSummary($result));
            }
        } catch (AnalyzerException $e) {
            $errOutput->writeln($e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Writes pre-formatted content verbatim, bypassing Console formatting.
     */
    private function writeRaw(OutputInterface $output, string $content): void
    {
        $output->write($content, false, OutputInterface::OUTPUT_RAW);
    }
}
