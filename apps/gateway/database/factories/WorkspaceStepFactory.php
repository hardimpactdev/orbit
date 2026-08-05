<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\Instance;
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

                return (int) (
                    Instance::query()->where('app_id', $appId)->value('id')
                    ?? Instance::factory()->create([
                        'app_id' => $appId,
                        'driver_config' => new OrbitInstanceDriverConfigData(
                            node_id: $app->node_id,
                            path: $app->path,
                            document_root: $app->document_root,
                            domain: $app->domain,
                        ),
                    ])->id
                );
            },
            'phase' => WorkspaceLifecyclePhase::Setup,
            'sort_order' => 1,
            'command' => 'composer install',
            'timeout_seconds' => WorkspaceStep::DEFAULT_TIMEOUT_SECONDS,
        ];
    }
}
