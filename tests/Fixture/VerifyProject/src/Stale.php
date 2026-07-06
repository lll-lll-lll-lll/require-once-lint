<?php

declare(strict_types=1);

namespace App;

// App\Stale: depone's own static resolution round-trips this file through
// the App\ => src/ PSR-4 rule (composer.json declares no classmap), so
// depone calls the require redundant. But the hand-written dumped
// autoload_classmap.php pins App\Stale to legacy/Stale.php instead --
// Composer's ClassLoader prefers the classmap, so verifying this finding
// against the real dump reports "mismatch": the dump is stale.
class Stale
{
}
