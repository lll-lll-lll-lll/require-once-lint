<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Widget.php';                // redundant: App\Widget round-trips via the dumped PSR-4 map
require_once __DIR__ . '/../legacy/DepShadow.php';          // conflicting: Acme\Lib\Thing is autoloaded from the dependency copy
require_once __DIR__ . '/../src/eager.php';                 // redundant: root autoload.files entry
require_once __DIR__ . '/../vendor/acme/lib/bootstrap.php'; // redundant: dependency autoload.files entry (eager)
