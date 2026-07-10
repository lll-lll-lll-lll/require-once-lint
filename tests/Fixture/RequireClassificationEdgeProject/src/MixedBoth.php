<?php

declare(strict_types=1);

namespace App\Sub;

// App\Sub\AlsoGone comes first and derives the missing path src/Sub/AlsoGone.php,
// so it is not autoload-reachable (which alone would leave the require merely
// load-bearing). App\Winner2 below then resolves to classmap/Winner2.php — the
// conflict wins: conflicting dominates a non-reachable class in one target.
class AlsoGone
{
}

namespace App;

class Winner2
{
}
