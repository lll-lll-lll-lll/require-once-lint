<?php

declare(strict_types=1);

namespace App;

// Regression fixture: a healthy, reachable class that uses the `::class`
// constant. Before the extractor fix, `UsesConst::class` fabricated a phantom
// `App\render` declaration (the following method name) and produced a spurious
// expected_path_missing error. It must round-trip cleanly with no finding.
class UsesConst
{
    public function name(): string
    {
        return UsesConst::class;
    }

    public function render(): int
    {
        return 1;
    }
}
