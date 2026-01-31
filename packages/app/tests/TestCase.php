<?php

namespace HardImpact\Orbit\App\Tests;

use HardImpact\Orbit\App\OrbitAppServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Configure factory namespace resolution for both core and app packages
        Factory::guessFactoryNamesUsing(function (string $modelName) {
            // Core models use core factories
            if (str_starts_with($modelName, 'HardImpact\\Orbit\\Core\\')) {
                return 'HardImpact\\Orbit\\Core\\Database\\Factories\\'.class_basename($modelName).'Factory';
            }

            // App models use app factories
            return 'HardImpact\\Orbit\\Ui\\Database\\Factories\\'.class_basename($modelName).'Factory';
        });
    }

    protected function getPackageProviders($app)
    {
        return [
            OrbitAppServiceProvider::class,
            InertiaServiceProvider::class,
        ];
    }

    protected function defineRoutes($router)
    {
        // Load app routes for testing
        OrbitAppServiceProvider::routes();
    }

    public function getEnvironmentSetUp($app)
    {
        // Set app key for encryption
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Configure Inertia for testing
        config()->set('inertia.testing.ensure_pages_exist', false);
        config()->set('inertia.testing.page_paths', []);

        // Configure orbit mode (multi_environment = desktop mode)
        config()->set('orbit.mode', 'web');
        config()->set('orbit.multi_environment', true);

        // Disable Vite in testing (no manifest needed)
        $app->bind('Illuminate\Foundation\Vite', function () {
            return new class
            {
                public function __invoke(...$args): string
                {
                    return '';
                }

                public function __call($method, $args)
                {
                    return $this;
                }
            };
        });

        // Run migrations from orbit-core
        $coreDir = __DIR__.'/../vendor/hardimpactdev/orbit-core/database/migrations';
        if (is_dir($coreDir)) {
            foreach (\Illuminate\Support\Facades\File::allFiles($coreDir) as $migration) {
                (include $migration->getRealPath())->up();
            }
        }

        // Run migrations from this package
        $uiDir = __DIR__.'/../database/migrations';
        if (is_dir($uiDir)) {
            foreach (\Illuminate\Support\Facades\File::allFiles($uiDir) as $migration) {
                (include $migration->getRealPath())->up();
            }
        }
    }
}
