<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Reachable.php'; // redundant: App\Reachable autoloads to this file
require_once __DIR__ . '/../src/WrongPath.php';  // needed: App\Sub\Missing derives a missing path — unreported
require_once __DIR__ . '/../src/Dup.php';        // conflicting: App\Dup autoloads from classmap/Dup.php
require_once __DIR__ . '/../src/helper.php';     // needed: no declared type, stays unreported
require __DIR__ . '/../src/Dup.php';             // plain require: classification is require_once-only, must stay unreported
