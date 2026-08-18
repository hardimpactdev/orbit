<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\WorkspaceStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceStep>
 */
class WorkspaceStepFactory extends Factory
{
    protected $model = WorkspaceStep::class;

    public function definition(): array
    {
        return [
            'instance_id' => static function (array $attributes): int {
                /** @var App $app */
                $app = isset($attributes['app_id'])
                    ? App::query()->findOrFail((int) $attributes['app_id'])
                    : App::factory()->create();
                $existing = Instance::query()->where('app_id', $app->id)->value('id');

                if ($existing !== null) {
                    return (int) $existing;
                }

                /** @var Node $node */
                $node = Node::factory()->create();

                return (int) Instance::factory()->create([
                    'app_id' => $app->id,
                    'driver_config' => new OrbitInstanceDriverConfigData(
                        node_id: $node->id,
                        node: $node->name,
                        path: '/home/orbit/apps/'.$app->name,
                        document_root: 'public',
                        domain: null,
                    ),
                ])->id;
            },
            'phase' => WorkspaceLifecyclePhase::Setup,
            'sort_order' => 1,
            'command' => 'composer install',
            'timeout_seconds' => WorkspaceStep::DEFAULT_TIMEOUT_SECONDS,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (WorkspaceStep $step): void {
            $step->offsetUnset('app_id');
        });
    }
}
