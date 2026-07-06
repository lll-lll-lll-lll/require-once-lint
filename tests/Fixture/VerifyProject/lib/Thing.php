<?php

declare(strict_types=1);

namespace Lib;

// Lib\Thing: composer.json's Lib\ => lib/ PSR-4 rule round-trips this file,
// so depone calls the require redundant. The dumped psr4 map omits the Lib\
// prefix entirely, so Composer's ClassLoader can't find this class at all --
// verifying this finding reports "unknown": the dump doesn't know about it.
class Thing
{
}
