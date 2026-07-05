<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/MixedShadow.php';  // conflicting: App\Winner is shadowed by classmap/Winner.php
require_once __DIR__ . '/../src/MixedMissing.php'; // fixable: App\Sub\Gone derives a missing path
require_once __DIR__ . '/../src/MixedGlobal.php';  // needed: GlobalEdgeHelper matches no rule — unreported
require_once __DIR__ . '/../src/eager.php';        // redundant: autoload.files entry, loaded eagerly
require_once __DIR__ . '/../src/MixedBoth.php';    // conflicting: the fixable candidate (App\Sub\AlsoGone) is overridden by the App\Winner2 conflict
