<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
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
            'app_id' => Project::factory(),
            'app_instance_id' => static function (array $attributes): ?int {
                $appId = is_numeric($attributes['app_id'] ?? null) ? (int) $attributes['app_id'] : null;
                $app = $appId === null ? null : Project::query()->find($appId);

                if (! $app instanceof Project) {
                    return null;
                }

                return (int) (
                    AppInstance::query()->where('app_id', $appId)->value('id')
                    ?? AppInstance::factory()->create([
                        'app_id' => $appId,
                        'driver_config' => new OrbitAppInstanceDriverConfigData(
                            node_id: $app->node_id,
                            path: $app->path,
                            document_root: $app->document_root,
                            domain: $app->domain,
                        ),
                    ])->id
                );
            },
            'node_id' => null,
            'target_name' => static function (array $attributes): string {
                $app = Project::query()->find((int) ($attributes['app_id'] ?? 0));
                $instance = AppInstance::query()->find((int) ($attributes['app_instance_id'] ?? 0));

                if (! $app instanceof Project || ! $instance instanceof AppInstance) {
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

    public function forApp(?Project $app = null): static
    {
        return $this->state(function (array $attributes) use ($app): array {
            /** @var Project $target */
            $target = $app ?? Project::factory()->create();
            /** @var AppInstance $instance */
            $instance = $target->instances()->first() ?? AppInstance::factory()->create([
                'app_id' => $target->id,
                'driver_config' => new OrbitAppInstanceDriverConfigData(
                    node_id: $target->node_id,
                    path: $target->path,
                    document_root: $target->document_root,
                    domain: $target->domain,
                ),
            ]);

            return [
                'schedule_key' => "app:{$target->name}.{$instance->name}:{$attributes['name']}",
                'scope' => 'app',
                'app_id' => $target->id,
                'app_instance_id' => $instance->id,
                'node_id' => null,
                'target_name' => "{$target->name}.{$instance->name}",
            ];
        });
    }

    public function forAppInstance(AppInstance $instance): static
    {
        return $this->state(fn (array $attributes): array => [
            'schedule_key' => "app:{$instance->app->name}.{$instance->name}:{$attributes['name']}",
            'scope' => 'app',
            'app_id' => $instance->app_id,
            'app_instance_id' => $instance->id,
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
                'app_instance_id' => null,
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
            'app_instance_id' => null,
            'node_id' => null,
            'target_name' => 'gateway',
        ]);
    }
}
