<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // Fixtures are analyzer input, not code to style: some deliberately carry
    // legacy shapes (a close tag, a missing final newline) the fixer would
    // "correct" and break the very case they exist to pin.
    ->exclude('Fixture')
    ->append([
        __DIR__ . '/bin/depone',
    ])
    ->name('*.php');

return (new Config())
    ->setRiskyAllowed(false)
    ->setFinder($finder)
    ->setRules([
        '@PSR12' => true,
    ]);
