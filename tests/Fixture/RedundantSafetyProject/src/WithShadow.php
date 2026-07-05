<?php

declare(strict_types=1);

namespace App;

// App\WithShadow round-trips, but App\Shadowed is autoloaded from
// classmap/Shadowed.php. Deleting the require would change which App\Shadowed
// loads, so it is NOT redundant.
class WithShadow
{
}

class Shadowed
{
}
