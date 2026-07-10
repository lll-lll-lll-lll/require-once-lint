<?php

declare(strict_types=1);

// A dynamic include: reported as unresolved, but never redundant/conflicting.
require_once $pluginDir . '/init.php';

echo \App\Foo::class;
