<?php

declare(strict_types=1);

namespace App;

// App\WithSideEffect round-trips, but the file also defines a constant and runs
// a top-level statement at include time — neither is reproduced by autoload, so
// the require is load-bearing.
const WITH_SIDE_EFFECT_VERSION = 1;

$GLOBALS['with_side_effect_loaded'] = true;

class WithSideEffect
{
}
