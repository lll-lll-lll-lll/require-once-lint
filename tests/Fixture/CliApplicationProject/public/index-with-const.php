<?php

declare(strict_types=1);

// SITE_ROOT is never defined anywhere in this fixture, so this expression is
// unresolvable and is reported with reason "complex" (no variable, method
// call, or static access token is present).
require_once SITE_ROOT . 'src/Bar.php';
