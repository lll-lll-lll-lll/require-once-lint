<?php

declare(strict_types=1);

// Dup is declared in both dup-a/One.php and dup-b/Two.php. Composer's
// classmap generator keeps the FIRST occurrence (dup-a/, per composer.json's
// array order), so only the require of dup-a/One.php is a safe round-trip;
// the require of dup-b/Two.php loads a copy Composer never autoloads from.
require_once __DIR__ . '/dup-b/Two.php';
require_once __DIR__ . '/dup-a/One.php';
