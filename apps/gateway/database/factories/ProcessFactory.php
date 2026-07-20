<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @extends Factory<Process>
 *
 * @mago-expect lint:cyclomatic-complexity
 */
class ProcessFactory extends Factory
{
    #[Override]
    protected $model = Process::class;

    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'owner_type' => Project::class,
            'owner_id' => Project::factory(),
            'name' => fake()->unique()->slug(1),
            'command' => 'php artisan queue:work',
            'restart_policy' => ProcessRestartPolicy::Never,
            'crash_notification' => ProcessCrashNotification::None,
            'runtime' => ProcessRuntime::Systemd,
            'tool' => null,
            'runtime_config' => [],
            'credentials' => null,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    public function forOwner(Model $owner, ?Node $node = null): static
    {
        $appInstance = $this->appInstanceForOwner($owner, $node);

        return $this->state(fn (): array => [
            'node_id' => $node->id ?? $this->nodeIdForOwner($owner, $appInstance),
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'app_instance_id' => $appInstance?->id,
            'runtime' => $this->runtimeForOwner($owner),
        ]);
    }

    private function runtimeForOwner(Model $owner): ProcessRuntime
    {
        if ($owner instanceof Project || $owner instanceof Workspace) {
            return ProcessRuntime::Systemd;
        }

        return ProcessRuntime::Docker;
    }

    private function nodeIdForOwner(Model $owner, ?AppInstance $appInstance): int
    {
        if ($owner instanceof Node) {
            return $owner->id;
        }

        if ($owner instanceof NodeRoleAssignment) {
            return $owner->node_id;
        }

        if ($owner instanceof Project) {
            $node = $appInstance instanceof AppInstance
                ? app(WorkspacePlacement::class)->nodeForInstance($appInstance)
                : null;

            return $node->id ?? $owner->node_id;
        }

        if ($owner instanceof Workspace) {
            $node = $appInstance instanceof AppInstance
                ? app(WorkspacePlacement::class)->nodeForInstance($appInstance)
                : null;

            if ($node instanceof Node) {
                return $node->id;
            }

            $owner->loadMissing('app');

            if ($owner->app instanceof Project) {
                return $owner->app->node_id;
            }
        }

        return Node::factory()->create()->id;
    }

    private function appInstanceForOwner(Model $owner, ?Node $node): ?AppInstance
    {
        if ($owner instanceof Workspace) {
            $owner->loadMissing('appInstance');

            return $owner->appInstance;
        }

        if (! $owner instanceof Project) {
            return null;
        }

        $instances = $owner->instances()->get();
        $matching = $instances
            ->first(
                static fn (AppInstance $instance): bool => (
                    $node === null
                    || app(WorkspacePlacement::class)->nodeForInstance($instance)?->is($node) === true
                ),
            );

        if ($matching instanceof AppInstance) {
            return $matching;
        }

        if ($instances->isNotEmpty()) {
            return $instances->first();
        }

        $servingNode = $node ?? $owner->node;

        return AppInstance::factory()->for($owner)->createOne([
            'name' => $owner->environment !== ''
                ? $owner->environment
                : 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $servingNode?->id,
                node: $servingNode?->name,
                path: $owner->path,
                document_root: $owner->document_root,
                domain: $owner->domain,
            ),
        ]);
    }
}
