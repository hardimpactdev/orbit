<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
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
            'owner_type' => App::class,
            'owner_id' => App::factory(),
            'name' => fake()->unique()->slug(1),
            // Omit label so Process::saving defaults it to name unless tests set one.
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
        $instance = $this->resolveInstanceForOwner($owner, $node);

        return $this->state(fn (): array => [
            'node_id' => $node->id ?? $this->nodeIdForOwner($owner, $instance),
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'instance_id' => $instance?->id,
            'runtime' => $this->runtimeForOwner($owner),
        ]);
    }

    private function runtimeForOwner(Model $owner): ProcessRuntime
    {
        if ($owner instanceof App || $owner instanceof Workspace) {
            return ProcessRuntime::Systemd;
        }

        return ProcessRuntime::Docker;
    }

    private function nodeIdForOwner(Model $owner, ?Instance $instance): int
    {
        if ($owner instanceof Node) {
            return $owner->id;
        }

        if ($owner instanceof NodeRoleAssignment) {
            return $owner->node_id;
        }

        // Placement is instance-authoritative: the serving node comes from the
        // concrete Orbit instance, never from a (removed) App shadow column.
        if ($instance instanceof Instance) {
            $node = app(WorkspacePlacement::class)->nodeForInstance($instance);

            if ($node instanceof Node) {
                return $node->id;
            }
        }

        if ($owner instanceof Workspace) {
            $owner->loadMissing('instance');
            $node = $owner->instance instanceof Instance
                ? app(WorkspacePlacement::class)->nodeForInstance($owner->instance)
                : null;

            if ($node instanceof Node) {
                return $node->id;
            }
        }

        return Node::factory()->create()->id;
    }

    private function resolveInstanceForOwner(Model $owner, ?Node $node): ?Instance
    {
        if ($owner instanceof Workspace) {
            $owner->loadMissing('instance');

            return $owner->instance;
        }

        if (! $owner instanceof App) {
            return null;
        }

        $instances = $owner->instances()->get();
        $matching = $instances
            ->first(
                static fn (Instance $instance): bool => (
                    $node === null
                    || app(WorkspacePlacement::class)->nodeForInstance($instance)?->is($node) === true
                ),
            );

        if ($matching instanceof Instance) {
            return $matching;
        }

        if ($instances->isNotEmpty()) {
            return $instances->first();
        }

        // The App is logical-only: with no concrete instance to inherit
        // placement from, mint a default Orbit placement on the given (or a
        // fresh) serving node.
        /** @var Node $servingNode */
        $servingNode = $node ?? Node::factory()->create();

        return Instance::factory()->for($owner)->createOne([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $servingNode->id,
                node: $servingNode->name,
                path: '/home/orbit/apps/'.$owner->name,
                document_root: 'public',
                domain: null,
            ),
        ]);
    }
}
