<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
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
