<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/constants.php';
require_once __DIR__ . '/../src/Greeting.php';
require_once __DIR__ . '/../src/helpers.php';

echo (new App\Greeting())->hello('depone'), PHP_EOL;
echo app_helper_shout('and it still runs'), PHP_EOL;
