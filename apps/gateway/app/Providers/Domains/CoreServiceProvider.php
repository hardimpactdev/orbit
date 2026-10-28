<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Console\GatewayBoostInstallCommand;
use App\Providers\GatewayGuidelineComposer;
use Illuminate\Support\ServiceProvider;
use Laravel\Boost\Console\InstallCommand;
use Laravel\Boost\Install\GuidelineComposer;

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (class_exists(GuidelineComposer::class)) {
            $this->app->singleton(GuidelineComposer::class, GatewayGuidelineComposer::class);
            $this->app->singleton(InstallCommand::class, GatewayBoostInstallCommand::class);
        }
    }
}
