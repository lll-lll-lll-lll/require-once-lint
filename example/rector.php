<?php

declare(strict_types=1);

use Example\Rector\RemoveDeponeRedundantRequireRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/legacy',
        __DIR__ . '/public',
        __DIR__ . '/src',
    ])
    ->withConfiguredRule(RemoveDeponeRedundantRequireRector::class, [
        RemoveDeponeRedundantRequireRector::REPORT_PATH => __DIR__ . '/depone-report.json',
        RemoveDeponeRedundantRequireRector::REPO_ROOT => __DIR__,
    ]);
