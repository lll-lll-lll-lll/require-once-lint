<?php

declare(strict_types=1);

namespace App;

// App\MixedShadow round-trips, but App\Winner is autoloaded from
// classmap/Winner.php. One healthy class must not make the require redundant:
// deleting it would swap which App\Winner definition loads → conflicting.
class MixedShadow
{
}

class Winner
{
}
