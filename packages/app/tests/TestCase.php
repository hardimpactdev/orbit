<?php

namespace HardImpact\Orbit\Ui\Tests;

use HardImpact\Orbit\Ui\UiServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'HardImpact\\Orbit\\Ui\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            UiServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

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
