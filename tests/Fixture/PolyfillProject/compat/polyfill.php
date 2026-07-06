<?php

declare(strict_types=1);

namespace App;

// App\Widget really lives at src/Widget.php (autoloaded). This file only stands
// in for it when it is missing, behind a class_exists guard. Deleting the
// require would not swap which Widget loads — the guard makes it idempotent —
// so this must be `needed` (declares types only conditionally), not conflicting.
if (!class_exists(Widget::class)) {
    class Widget
    {
    }
}
