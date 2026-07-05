<?php

declare(strict_types=1);

namespace App;

// App\WithFunction round-trips, but the file also declares a function.
// Autoload loads this file only when App\WithFunction is referenced, so code
// that calls with_function_helper() first relies on the require. Not deletable.
class WithFunction
{
}

function with_function_helper(): int
{
    return 1;
}
