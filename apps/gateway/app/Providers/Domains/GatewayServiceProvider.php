<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\GatewayWebCaddySiteSource;
use Illuminate\Support\ServiceProvider;

final class GatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([GatewayWebCaddySiteSource::class], NodeCaddySiteSource::class);
    }
}
