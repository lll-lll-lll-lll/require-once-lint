<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Pure.php';         // redundant: pure class, round-trips
require_once __DIR__ . '/../src/WithShadow.php';   // NOT redundant: App\Shadowed autoloads elsewhere
require_once __DIR__ . '/../src/WithFunction.php';  // NOT redundant: also declares a function
require_once __DIR__ . '/../src/eager.php';        // redundant: autoload.files entry
