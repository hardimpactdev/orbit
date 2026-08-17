<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<ProxyRoute>
 * @mago-expect lint:cyclomatic-complexity
 */
class ProxyRouteFactory extends Factory
{
    protected $model = ProxyRoute::class;

    public function configure(): static
    {
        return $this->afterMaking(static function (ProxyRoute $route): void {
            if (! in_array($route->owner_type, ['app', 'workspace', 'app-analytics', 'app-websocket'], true)) {
                return;
            }

            if (! is_int($route->instance_id)) {
                throw new RuntimeException(
                    "ProxyRoute factory requires an explicit instance_id for owner_type={$route->owner_type}.",
                );
            }

            $instance = Instance::query()->find($route->instance_id);

            if (! $instance instanceof Instance) {
                throw new RuntimeException(
                    "ProxyRoute factory instance_id={$route->instance_id} does not identify an Instance.",
                );
            }

            if ($route->app_id !== $instance->app_id) {
                throw new RuntimeException(
                    "ProxyRoute factory app_id={$route->app_id} conflicts with instance_id={$instance->id} app_id={$instance->app_id}.",
                );
            }
        });
    }

    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'domain' => fake()->unique()->bothify('route-####.test'),
            'app_id' => null,
            'workspace_id' => null,
            'instance_id' => null,
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'source_hash' => hash('sha256', fake()->uuid()),
            'config' => [
                'upstream' => 'http://127.0.0.1:8080',
            ],
        ];
    }

    public function forApp(?App $app = null, ?Instance $instance = null): self
    {
        if ($instance instanceof Instance && ! $app instanceof App) {
            $instance->loadMissing('app');
            $app = $instance->app;
        }

        if (! $app instanceof App) {
            $createdApp = App::factory()->create();

            if (! $createdApp instanceof App) {
                throw new RuntimeException('ProxyRoute factory could not create an App.');
            }

            $app = $createdApp;
        }

        if (! $instance instanceof Instance) {
            $instances = $app->instances()->get();

            if ($instances->count() > 1) {
                throw new RuntimeException(
                    'ProxyRoute factory forApp state requires an explicit Instance when the App has multiple instances.',
                );
            }

            $instance = $instances->first();
        }

        if (! $instance instanceof Instance) {
            $createdInstance = Instance::factory()->for($app)->create();

            if (! $createdInstance instanceof Instance) {
                throw new RuntimeException('ProxyRoute factory could not create an Instance.');
            }

            $instance = $createdInstance;
        }

        if ($instance->app_id !== $app->id) {
            throw new RuntimeException('ProxyRoute factory forApp state received an Instance owned by another App.');
        }

        return $this->state(fn (): array => [
            'app_id' => $instance->app_id,
            'instance_id' => $instance->id,
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [],
        ]);
    }
}
