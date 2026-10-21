<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Extensions\GatewayExtensionState;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Foundation\Testing\WithCachedConfig;
use LaravelZero\Framework\Testing\TestCase as BaseTestCase;
use Symfony\Component\Console\Command\Command;

abstract class TestCase extends BaseTestCase
{
    use WithCachedConfig {
        markConfigCached as private markFrameworkConfigCached;
    }

    /** @var list<class-string<Command>>|null */
    private static ?array $commandClasses = null;

    protected function setUp(): void
    {
        parent::setUp();

        GatewayExtensionState::reset();

        self::$commandClasses ??= collect($this->app->make(Kernel::class)->all())
            ->filter(static fn (Command $command): bool => str_starts_with($command::class, 'App\\Commands\\'))
            ->map(static fn (Command $command): string => $command::class)
            ->unique()->values()->all();
    }

    protected function markConfigCached(Application $app): void
    {
        $this->markFrameworkConfigCached($app);

        $commands = self::$commandClasses;
        if ($commands !== null) {
            LoadConfiguration::alwaysUse(static function () use ($commands): array {
                $config = CachedState::$cachedConfig;
                $config['commands']['paths'] = [];
                $config['commands']['add'] = array_values(array_unique([
                    ...$config['commands']['add'], ...$commands,
                ]));

                return $config;
            });
        }
    }
}
