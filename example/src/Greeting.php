<?php

declare(strict_types=1);

namespace App;

final class Greeting
{
    public function hello(string $name): string
    {
        return sprintf('Hello, %s! (from %s)', $name, APP_NAME);
    }
}
