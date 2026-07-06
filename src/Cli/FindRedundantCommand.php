<?php

declare(strict_types=1);

namespace Depone\Internal\Cli;

use Depone\Internal\Core\Analyzer;
use Depone\Internal\Core\DependencyGraph;
use Depone\Internal\Core\OutputFormatter as InternalOutputFormatter;
use Depone\Internal\Exception\AnalyzerException;
use Depone\Internal\Resolver\ComposerLoaderVerifier;
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

    /** Analysis ran; no redundant, fixable, or conflicting require was found. */
    public const EXIT_OK = 0;
    /** Analysis ran; at least one redundant, fixable, or conflicting require was reported. */
    public const EXIT_FINDINGS = 1;
    /** The analysis could not run (unreadable composer.json, invalid invocation, ...). */
    public const EXIT_ERROR = 2;

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
            ->setDescription('Classify require_once statements by their relationship to Composer autoload (redundant, fixable, conflicting).')
            ->addOption('trace', null, InputOption::VALUE_REQUIRED, 'Show reverse caller traces for the given file path (repo relative) — who requires this file?')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: "text" (default) or "json"', 'text')
            ->addOption('explain', null, InputOption::VALUE_NONE, 'Prepend the coverage summary and print autoload evidence under each redundant finding (text only; human-facing output, not part of the frozen text contract)')
            ->addOption('verify', null, InputOption::VALUE_NONE, 'Cross-check every redundant finding against the autoload maps Composer dumped under vendor/composer/ (uses Composer\'s own ClassLoader; never executes project code)');
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

        $formatOption = $input->getOption('format');
        $format = is_string($formatOption) ? $formatOption : 'text';
        if ($format !== 'text' && $format !== 'json') {
            $errOutput->writeln("unknown format \"{$format}\" (expected \"text\" or \"json\")");
            return self::EXIT_ERROR;
        }

        $explain = $input->getOption('explain') === true;
        $verify = $input->getOption('verify') === true;

        if ($traceTarget !== null) {
            // --trace is an exclusive mode: none of the other output-shaping
            // flags apply to it.
            $incompatible = ['--format=json' => $format === 'json', '--explain' => $explain, '--verify' => $verify];
            foreach ($incompatible as $flag => $given) {
                if ($given) {
                    $errOutput->writeln("{$flag} cannot be combined with --trace");
                    return self::EXIT_ERROR;
                }
            }
        }

        if ($explain && $format === 'json') {
            $errOutput->writeln('--explain only applies to the text format');
            return self::EXIT_ERROR;
        }

        if ($verify && !ComposerLoaderVerifier::isAvailable($repoRoot)) {
            $errOutput->writeln('composer autoload maps not found under vendor/composer — run "composer install" (or "composer dump-autoload") first');
            return self::EXIT_ERROR;
        }

        try {
            $analyzer = new Analyzer($repoRoot);
            $result = $analyzer->run();

            $formatter = new InternalOutputFormatter();
            if ($traceTarget !== null) {
                // Trace output is informational and never fails the build.
                $graph = new DependencyGraph($result['edges'], $repoRoot);
                $trace = $graph->buildReverseTrace($traceTarget, self::MAX_PATHS, self::MAX_DEPTH);
                $this->writeRaw($output, $formatter->formatReverseTrace($trace));
                return self::EXIT_OK;
            }

            if ($verify) {
                $verification = (new ComposerLoaderVerifier($repoRoot))->verifyFindings($result['redundant'], $repoRoot);
                $result['redundant'] = $verification['entries'];
                $mismatches = $verification['mismatches'];
            } else {
                $mismatches = null;
            }

            if ($format === 'json') {
                $this->writeRaw($output, $formatter->formatJson($result, $mismatches));
            } else {
                $text = $explain ? $formatter->formatSummaryWithEvidence($result) : $formatter->formatSummary($result);
                if ($mismatches !== null) {
                    $text .= $formatter->formatVerifySection($mismatches);
                }
                $this->writeRaw($output, $text);
            }

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
            $hasFindings = $hasFindings || ($mismatches !== null && $mismatches !== []);

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
