<?php

declare(strict_types=1);

namespace App;

// App\Dup also lives in classmap/Dup.php. The classmap entry wins resolution,
// so autoload loads the other copy — a require_once to this file is conflicting.
class Dup
{
}
