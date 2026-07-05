<?php

declare(strict_types=1);

namespace App\Sub;

// The App\ => src/ rule matches App\Sub\Missing and derives src/Sub/Missing.php,
// but this file lives at src/WrongPath.php, so the class never autoloads. A
// require_once to it is fixable: correct the autoload config, then drop it.
class Missing
{
}
