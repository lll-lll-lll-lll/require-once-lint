<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Greeting.php';

echo (new App\Greeting())->hello('legacy'), PHP_EOL;
