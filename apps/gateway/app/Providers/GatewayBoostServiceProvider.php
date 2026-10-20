<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\GatewayBoostInstallCommand;
use Illuminate\Support\ServiceProvider;
use Laravel\Boost\BoostServiceProvider;
use Laravel\Boost\Console\InstallCommand;
use Laravel\Boost\Install\GuidelineComposer;

final class GatewayBoostServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (class_exists(GuidelineComposer::class)) {
            $this->app->singleton(GuidelineComposer::class, GatewayGuidelineComposer::class);
            $this->app->singleton(InstallCommand::class, GatewayBoostInstallCommand::class);
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        if (! class_exists(BoostServiceProvider::class)) {
            return;
        }

        $this->app->register(new class($this->app) extends BoostServiceProvider
        {
            protected function registerRoutes(): void {}
        });
    }
}
