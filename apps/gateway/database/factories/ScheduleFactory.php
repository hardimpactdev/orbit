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
            'scope' => 'instance',
            'instance_id' => Instance::factory(),
            'node_id' => null,
            'target_name' => static function (array $attributes): string {
                $instance = Instance::query()
                    ->with('app')
                    ->find((int) ($attributes['instance_id'] ?? 0));

                if (! $instance instanceof Instance) {
                    return 'missing-app.missing-instance';
                }

                return "{$instance->app->name}.{$instance->name}";
            },
            'schedule_key' =>
                static fn (array $attributes): string => "instance:{$attributes['target_name']}:{$attributes['name']}",
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
                'schedule_key' => "instance:{$target->name}.{$instance->name}:{$attributes['name']}",
                'scope' => 'instance',
                'instance_id' => $instance->id,
                'node_id' => null,
                'target_name' => "{$target->name}.{$instance->name}",
            ];
        });
    }

    public function forInstance(Instance $instance): static
    {
        return $this->state(fn (array $attributes): array => [
            'schedule_key' => "instance:{$instance->app->name}.{$instance->name}:{$attributes['name']}",
            'scope' => 'instance',
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
            'instance_id' => null,
            'node_id' => null,
            'target_name' => 'gateway',
        ]);
    }
}
