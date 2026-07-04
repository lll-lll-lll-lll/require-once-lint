<?php

declare(strict_types=1);

namespace App;

final class Bar
{
    public function createFoo(): Foo
    {
        return new Foo();
    }
}
