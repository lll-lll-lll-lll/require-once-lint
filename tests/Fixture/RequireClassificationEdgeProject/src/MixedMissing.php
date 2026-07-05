<?php

declare(strict_types=1);

namespace App;

// App\MixedMissing round-trips, but App\Sub\Gone below derives the missing
// path src/Sub/Gone.php. The require is load-bearing for Gone → fixable, not
// redundant.
class MixedMissing
{
}

namespace App\Sub;

class Gone
{
}
