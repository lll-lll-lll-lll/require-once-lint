<?php

declare(strict_types=1);

// Declares no types, so it can never be autoloaded. A require_once to it is a
// legitimate "needed" require and must NOT be reported in any section.
function require_classification_helper(): string
{
    return 'helper';
}
