<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<ProxyRoute>
 * @mago-expect lint:cyclomatic-complexity
 */
class ProxyRouteFactory extends Factory
{
    protected $model = ProxyRoute::class;

    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'domain' => fake()->unique()->bothify('route-####.test'),
            'app_id' => null,
            'workspace_id' => null,
            'instance_id' => static function (array $attributes): ?int {
                $ownerType = $attributes['owner_type'] ?? 'custom';

                if ($ownerType === 'workspace' && is_numeric($attributes['workspace_id'] ?? null)) {
                    $instanceId = Workspace::query()
                        ->whereKey((int) $attributes['workspace_id'])
                        ->value('instance_id');

                    return is_numeric($instanceId) ? (int) $instanceId : null;
                }

                if (! in_array($ownerType, ['app', 'app-analytics', 'app-websocket'], true)) {
                    return null;
                }

                $appId = is_numeric($attributes['app_id'] ?? null) ? (int) $attributes['app_id'] : null;

                if ($appId === null) {
                    return null;
                }

                $existing = Instance::query()->where('app_id', $appId)->value('id');

                if (is_numeric($existing)) {
                    return (int) $existing;
                }

                $instance = Instance::factory()->create(['app_id' => $appId]);

                return $instance instanceof Instance ? $instance->id : null;
            },
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
        if (! $app instanceof App) {
            $createdApp = App::factory()->create();

            if (! $createdApp instanceof App) {
                throw new RuntimeException('ProxyRoute factory could not create an App.');
            }

            $app = $createdApp;
        }

        if (! $instance instanceof Instance) {
            $instance = $app->instances()->first();
        }

        if (! $instance instanceof Instance) {
            $createdInstance = Instance::factory()->for($app)->create();

            if (! $createdInstance instanceof Instance) {
                throw new RuntimeException('ProxyRoute factory could not create an Instance.');
            }

            $instance = $createdInstance;
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
