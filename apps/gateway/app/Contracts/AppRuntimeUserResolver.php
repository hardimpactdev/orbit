<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\App;
use App\Models\Instance;

interface AppRuntimeUserResolver
{
    public function forApp(App $app, ?Instance $instance = null): string;
}
