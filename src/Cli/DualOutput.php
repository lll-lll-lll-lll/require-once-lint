<?php

declare(strict_types=1);

namespace Depone\Internal\Cli;

use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * @internal
 */
final class DualOutput extends StreamOutput implements ConsoleOutputInterface
{
    private OutputInterface $errorOutput;

    /** @var ConsoleSectionOutput[] */
    private array $sections = [];

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(mixed $stdout, mixed $stderr)
    {
        parent::__construct($stdout, self::VERBOSITY_NORMAL, false);
        $this->errorOutput = new StreamOutput($stderr, self::VERBOSITY_NORMAL, false);
    }

    public function section(): ConsoleSectionOutput
    {
        return new ConsoleSectionOutput($this->getStream(), $this->sections, $this->getVerbosity(), $this->isDecorated(), $this->getFormatter());
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->errorOutput;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->errorOutput = $error;
    }
}
