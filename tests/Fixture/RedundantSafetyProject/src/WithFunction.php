<?php

declare(strict_types=1);

namespace App;

// App\WithFunction round-trips, but the file also declares a function that
// autoload never loads, so the require is load-bearing: NOT redundant.
class WithFunction
{
}

function with_function_helper(): int
{
    return 1;
}
