<?php

declare(strict_types=1);

namespace App;

// The dumped autoload_classmap.php pins App\Stale here instead of
// src/Stale.php. composer.json itself declares no classmap, so depone's own
// static resolution never sees this file -- it exists purely so
// Composer\Autoload\ClassLoader::findFile() has somewhere real to point to.
class Stale
{
}
