<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Cli;

final class CliOptions
{
    /**
     * @param array<string, string> $consts
     */
    public function __construct(
        public bool    $json               = false,
        public bool    $help               = false,
        public ?string $trace              = null,
        public ?string $deps               = null,
        public int     $maxPaths           = 20,
        public int     $maxDepth           = 25,
        public bool    $includeNonAutoload = false,
        public array   $consts             = [],
    ) {
    }
}
