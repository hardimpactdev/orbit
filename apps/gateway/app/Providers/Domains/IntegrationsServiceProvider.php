<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Integrations\IntegrationSecretSource;
use App\Domain\Operations\OperationSecretSource;
use Illuminate\Support\ServiceProvider;

final class IntegrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([IntegrationSecretSource::class], OperationSecretSource::class);
    }
}
