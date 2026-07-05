<?php

declare(strict_types=1);

namespace App\Sub;

// App\Sub\AlsoGone comes first and derives the missing path src/Sub/AlsoGone.php,
// registering a fixable candidate. App\Winner2 below then resolves to
// classmap/Winner2.php — the conflict must override the pending fixable
// candidate: conflicting dominates fixable within a single target.
class AlsoGone
{
}

namespace App;

class Winner2
{
}
