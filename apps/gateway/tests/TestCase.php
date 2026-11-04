<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        // The test application reads the tracked `.env.example`, as CI does, not the untracked `.env`. A checkout
        // without `.env` would otherwise give every test a PHP warning from the environment loader, and a
        // developer's `.env` would make local results differ from CI. Child processes that boot the Gateway
        // themselves, such as `artisan` calls, still read `.env` when it exists.
        $app->loadEnvironmentFrom('.env.example');

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        if (isset(CachedState::$cachedConfig, $this->traitsUsedByTest[WithCachedConfig::class])) {
            $this->markConfigCached($app);
        }

        if (isset(CachedState::$cachedRoutes, $this->traitsUsedByTest[WithCachedRoutes::class])) {
            $app->booting(fn (): mixed => $this->markRoutesCached($app));
        }

        TestDatabaseGuard::register($app);
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('swamp.ssh_public_key',
            'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('t', 32)));

        return $app;
    }
}
