<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    protected $model = Schedule::class;

    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'name' => $name,
            'scope' => 'app',
            'app_id' => App::factory(),
            'instance_id' => static function (array $attributes): ?int {
                $appId = is_numeric($attributes['app_id'] ?? null) ? (int) $attributes['app_id'] : null;
                $app = $appId === null ? null : App::query()->find($appId);

                if (! $app instanceof App) {
                    return null;
                }

                $existing = Instance::query()->where('app_id', $appId)->value('id');

                if ($existing !== null) {
                    return (int) $existing;
                }

                // Logical-only App: mint a default Orbit placement.
                /** @var Node $node */
                $node = Node::factory()->create();

                return (int) Instance::factory()->create([
                    'app_id' => $appId,
                    'driver_config' => new OrbitInstanceDriverConfigData(
                        node_id: $node->id,
                        node: $node->name,
                        path: '/home/orbit/apps/'.$app->name,
                        document_root: 'public',
                        domain: null,
                    ),
                ])->id;
            },
            'node_id' => null,
            'target_name' => static function (array $attributes): string {
                $app = App::query()->find((int) ($attributes['app_id'] ?? 0));
                $instance = Instance::query()->find((int) ($attributes['instance_id'] ?? 0));

                if (! $app instanceof App || ! $instance instanceof Instance) {
                    return 'missing-app.missing-instance';
                }

                return "{$app->name}.{$instance->name}";
            },
            'schedule_key' =>
                static fn (array $attributes): string => "app:{$attributes['target_name']}:{$attributes['name']}",
            'interval' => 'every minute',
            'timezone' => 'UTC',
            'execution_type' => 'command',
            'execution_value' => 'php artisan schedule:run',
            'timeout_seconds' => 900,
            'enabled' => true,
            'status' => 'expected',
        ];
    }

    public function forApp(?App $app = null): static
    {
        return $this->state(function (array $attributes) use ($app): array {
            /** @var App $target */
            $target = $app ?? App::factory()->create();
            /** @var Instance $instance */
            $instance = $target->instances()->first() ?? (function () use ($target): Instance {
                /** @var Node $node */
                $node = Node::factory()->create();
                /** @var Instance $created */
                $created = Instance::factory()->create([
                    'app_id' => $target->id,
                    'driver_config' => new OrbitInstanceDriverConfigData(
                        node_id: $node->id,
                        node: $node->name,
                        path: '/home/orbit/apps/'.$target->name,
                        document_root: 'public',
                        domain: null,
                    ),
                ]);

                return $created;
            })();

            return [
                'schedule_key' => "app:{$target->name}.{$instance->name}:{$attributes['name']}",
                'scope' => 'app',
                'app_id' => $target->id,
                'instance_id' => $instance->id,
                'node_id' => null,
                'target_name' => "{$target->name}.{$instance->name}",
            ];
        });
    }

    public function forInstance(Instance $instance): static
    {
        return $this->state(fn (array $attributes): array => [
            'schedule_key' => "app:{$instance->app->name}.{$instance->name}:{$attributes['name']}",
            'scope' => 'app',
            'app_id' => $instance->app_id,
            'instance_id' => $instance->id,
            'node_id' => null,
            'target_name' => "{$instance->app->name}.{$instance->name}",
        ]);
    }

    public function forNode(?Node $node = null): static
    {
        return $this->state(function (array $attributes) use ($node): array {
            /** @var Node $target */
            $target = $node ?? Node::factory()->create();

            return [
                'schedule_key' => "node:{$target->name}:{$attributes['name']}",
                'scope' => 'node',
                'app_id' => null,
                'instance_id' => null,
                'node_id' => $target->id,
                'target_name' => $target->name,
            ];
        });
    }

    public function orbit(): static
    {
        return $this->state(fn (array $attributes): array => [
            'schedule_key' => "orbit:gateway:{$attributes['name']}",
            'scope' => 'orbit',
            'app_id' => null,
            'instance_id' => null,
            'node_id' => null,
            'target_name' => 'gateway',
        ]);
    }
}
