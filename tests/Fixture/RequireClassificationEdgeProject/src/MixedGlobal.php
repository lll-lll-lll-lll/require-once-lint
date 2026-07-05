<?php

declare(strict_types=1);

// App\MixedGlobal round-trips, but GlobalEdgeHelper matches no autoload rule.
// Deleting the require would leave GlobalEdgeHelper undefined, so the require
// is needed and must stay unreported — in every section.

namespace App {
    class MixedGlobal
    {
    }
}

namespace {
    class GlobalEdgeHelper
    {
    }
}
