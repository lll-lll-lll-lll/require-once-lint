<?php

declare(strict_types=1);

namespace App\Sub;

// The App\ => src/ rule matches App\Sub\Missing and derives src/Sub/Missing.php,
// but this file lives at src/WrongPath.php, so the class never autoloads. The
// class is not autoload-reachable, so the require_once stays load-bearing and
// is left unreported.
class Missing
{
}
