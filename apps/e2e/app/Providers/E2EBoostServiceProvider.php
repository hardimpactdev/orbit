<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Boost\BoostServiceProvider;
use Laravel\Boost\Install\GuidelineComposer;

final class E2EBoostServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        if (! class_exists(BoostServiceProvider::class)) {
            return;
        }

        config(['view.compiled' => config('view.compiled') ?: storage_path('framework/cache')]);

        $this->app->register(new class($this->app) extends BoostServiceProvider
        {
            protected function registerRoutes(): void {}
        });

        $this->app->singleton(GuidelineComposer::class, E2EGuidelineComposer::class);
    }
}
