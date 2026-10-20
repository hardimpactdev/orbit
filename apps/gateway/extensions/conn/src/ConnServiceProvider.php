<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn;

use App\Domain\Activity\ActivityInputRedaction;
use App\Providers\ExtensionServiceProvider;
use Orbit\Extensions\Conn\Infrastructure\ConnActivityInputRedaction;

/** The conn extension: Conn devices, shared profiles, and T3 server pairing ([Conn layer](/reference/conn)). */
final class ConnServiceProvider extends ExtensionServiceProvider
{
    public function name(): string
    {
        return 'conn';
    }

    public function description(): string
    {
        return 'Conn devices sign in as Nodes, share settings through profiles, and pair with registered T3 servers.';
    }

    public function register(): void
    {
        $this->contribute(ActivityInputRedaction::class, ConnActivityInputRedaction::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
