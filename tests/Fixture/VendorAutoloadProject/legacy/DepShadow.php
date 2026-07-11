<?php

declare(strict_types=1);

namespace Acme\Lib;

// Declares Acme\Lib\Thing, but the dependency (vendor/acme/lib) provides the
// class through its own PSR-4 rule. Only visible once the dumped vendor
// autoload is consulted: requiring this file loads a shadowed copy of the
// class Composer actually autoloads from the dependency — conflicting.
class Thing
{
}
