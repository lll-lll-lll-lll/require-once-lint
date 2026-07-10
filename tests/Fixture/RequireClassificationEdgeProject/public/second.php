<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/MixedMissing.php'; // same load-bearing target as index.php — served from the per-target memo
require_once __DIR__ . '/../src/MixedGlobal.php';  // same needed target — memoized null, stays silent
require_once __DIR__ . '/../src/DoesNotExist.php'; // nonexistent target: no declarations, stays silent everywhere
require_once __DIR__ . '/../dev/dev-eager.php';    // redundant: autoload-dev files entry, loaded eagerly
require_once __DIR__ . '/../src/WithFunction.php';   // needed: App\WithFunction round-trips but the file also declares a function
require_once __DIR__ . '/../src/WithSideEffect.php'; // needed: round-trips but defines a const and runs a top-level statement
