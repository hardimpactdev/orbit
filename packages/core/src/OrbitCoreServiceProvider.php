<?php

declare(strict_types=1);

namespace Orbit\Core;

use Illuminate\Support\ServiceProvider;

class OrbitCoreServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void {}

    public function boot(): void {}
}
