<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'app_id' => App::factory(),
            'app_instance_id' => static function (array $attributes): int {
                $appId = (int) $attributes['app_id'];
                $app = App::query()->findOrFail($appId);

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
            'name' => $name,
            'path' => "/home/orbit/apps/docs/workspaces/{$name}",
            'php_version' => null,
            'agent_ide' => null,
            'agent_ide_workspace_id' => null,
            'lifecycle_status' => WorkspaceLifecycleStatus::Expected,
        ];
    }
}
