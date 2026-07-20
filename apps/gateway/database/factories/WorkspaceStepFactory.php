<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\WorkspaceLifecyclePhase;
use App\Models\AppInstance;
use App\Models\Project;
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
            'app_id' => Project::factory(),
            'app_instance_id' => static function (array $attributes): int {
                $appId = (int) $attributes['app_id'];
                $app = Project::query()->findOrFail($appId);

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
            'phase' => WorkspaceLifecyclePhase::Setup,
            'sort_order' => 1,
            'command' => 'composer install',
            'timeout_seconds' => WorkspaceStep::DEFAULT_TIMEOUT_SECONDS,
        ];
    }
}
