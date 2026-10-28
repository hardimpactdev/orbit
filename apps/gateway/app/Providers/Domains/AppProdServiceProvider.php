<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\AppProd\AppProdCaddyManager;
use App\Infrastructure\AppProd\RemoteAppProdCaddyManager;
use Illuminate\Support\ServiceProvider;

final class AppProdServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        AppProdCaddyManager::class => RemoteAppProdCaddyManager::class,
    ];
}
