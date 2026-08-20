<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
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
            'instance_id' => static function (array $attributes): int {
                $appId = (int) $attributes['app_id'];
                $app = App::query()->findOrFail($appId);
                $existing = Instance::query()->where('app_id', $appId)->value('id');

                if ($existing !== null) {
                    return (int) $existing;
                }

                // Logical-only App: mint a default Orbit placement so the
                // workspace has a concrete instance to belong to.
                /** @var Node $node */
                $node = Node::factory()->create();

                return (int) Instance::factory()->create([
                    'app_id' => $appId,
                    'php_version' => '8.5',
                    'driver_config' => new OrbitInstanceDriverConfigData(
                        node_id: $node->id,
                        node: $node->name,
                        path: '/home/orbit/apps/'.$app->name,
                        document_root: 'public',
                        domain: null,
                    ),
                ])->id;
            },
            'name' => $name,
            'path' => "/home/orbit/apps/docs/workspaces/{$name}",
            'php_version' => '8.5',
            'adopted' => false,
            'lifecycle_status' => WorkspaceLifecycleStatus::Expected,
        ];
    }
}
