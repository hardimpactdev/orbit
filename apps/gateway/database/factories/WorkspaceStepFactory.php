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
            'app_id' => App::factory(),
            'instance_id' => static function (array $attributes): int {
                $appId = (int) $attributes['app_id'];
                $app = App::query()->findOrFail($appId);
                $existing = Instance::query()->where('app_id', $appId)->value('id');

                if ($existing !== null) {
                    return (int) $existing;
                }

                // Logical-only App: mint a default Orbit placement so the
                // workspace step has a concrete instance to belong to.
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
            'phase' => WorkspaceLifecyclePhase::Setup,
            'sort_order' => 1,
            'command' => 'composer install',
            'timeout_seconds' => WorkspaceStep::DEFAULT_TIMEOUT_SECONDS,
        ];
    }
}
