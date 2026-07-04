<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Bar.php';  // PSR-4 autoload target -> redundant
require_once __DIR__ . '/../lib/Util.php'; // not registered in autoload -> non-autoload require_once
