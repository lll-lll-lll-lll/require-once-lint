<?php

declare(strict_types=1);

namespace Depone\Internal\Cli;

use Depone\Internal\Core\AutoloadDoctor;
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
final class DoctorCommand extends Command
{
    public const NAME = 'doctor';

    public function __construct(private ?string $repoRoot = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Report files the Composer autoloader can never reach.')
            ->addOption(
                'min-severity',
                null,
                InputOption::VALUE_REQUIRED,
                'Lowest severity section to report (error|warning|info). Defaults to error.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $repoRoot = $this->repoRoot ?? getcwd();
        if ($repoRoot === false) {
            $errOutput->writeln('failed to resolve current working directory');
            return Command::FAILURE;
        }

        $minSeverityOption = $input->getOption('min-severity');
        $minSeverity = 'error';
        if ($minSeverityOption === 'error' || $minSeverityOption === 'warning' || $minSeverityOption === 'info') {
            $minSeverity = $minSeverityOption;
        } elseif ($minSeverityOption !== null) {
            $value = is_string($minSeverityOption) ? $minSeverityOption : get_debug_type($minSeverityOption);
            $errOutput->writeln("invalid --min-severity value \"{$value}\": expected 'error', 'warning' or 'info'");
            return Command::FAILURE;
        }

        try {
            $doctor = new AutoloadDoctor($repoRoot);
            $result = $doctor->run();

            $formatter = new InternalOutputFormatter();
            $this->writeRaw($output, $formatter->formatDoctor($result, $minSeverity));
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
