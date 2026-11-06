<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageRefreshIntent;
use App\Domain\TaskVms\TaskVmLaunchConfig;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\MachineSoftware\VitePlusRuntimeLock;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;

/**
 * Pins one image attempt as one Operation: the host to build on, the builder and smoke names, and the recipe whose phases the
 * task VM role playbook applies. No build jobs, queue chain or external effects.
 */
final readonly class StartTaskVmImageOperationAction
{
    public function __construct(private StartOperationAction $start, private TaskVmSettings $settings, private RecipeLoader $recipes, private SwampSshExecutionInput $keys) {}

    public function execute(TaskVmImageBuild $build): Operation
    {
        $existing = Operation::query()->where('kind', 'task_vm.image_build')->where('target_type', 'task_vm_image')->where('target_id', $build->id)
            ->where('input->attempt', $build->attempt)->latest('id')->first();
        if ($existing !== null) {
            return $existing;
        }
        if (TaskVmImageRefreshIntent::open($build->host_node_id) instanceof Operation) {
            // The caller's transaction rolls the build row back; the scheduler tries again after the refresh.
            throw new TaskVmException('task_vm.image_build_running', 'An image refresh is running on this host. The image build starts after it ends.', 409);
        }
        $host = $this->settings->host($build->host_node_id);
        $node = $build->hostNode;
        $recipe = $this->recipes->decode((string) $build->recipe_json);
        $architecture = MachineRecipe::architecture($node->architecture)
            ?? throw new TaskVmException('task_vm.image_build_failed', 'The image host architecture is unsupported.');
        $recipe->profile('base', $architecture);
        $pi = $recipe->tools['pi']->artifacts[$architecture] ?? throw new TaskVmException('task_vm.image_build_failed', 'The recipe holds no Pi server for this architecture.');
        $gateway = Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($query) => $query
            ->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
        $identity = $this->keys->identity();
        $builder = (string) $build->builder_vm_name;

        return $this->start->execute('task_vm.image_build', 'task_vm_image', $build->id, $node->id, [
            'build_id' => $build->id, 'attempt' => $build->attempt, 'host_node_id' => $node->id, 'identity' => $recipe->identity, 'generation' => $build->recipe_generation,
            'project' => $host->project, 'pool' => $host->pool, 'builder' => $builder, 'smoke' => $builder.'-smoke', 'network' => $host->network,
            'cpus' => $host->cpus, 'memory' => $host->memory, 'disk' => $host->disk,
            'source' => $build->source_fingerprint ?? TaskVmHost::SourceImage,
            'candidate_alias' => ($build->images['base']['alias'] ?? RecipeImageBuilds::alias($host->imageAlias, $recipe->identity, 'base', $build->id)).'-attempt-'.$build->attempt,
            'base_alias' => $host->imageAlias, 'previous_alias' => $host->imageAlias.'-previous',
            // The orbit account of the builder and of every clone accepts the key of the Swamp control Node.
            'launch_config' => TaskVmLaunchConfig::for($identity['public_key'], sshServer: true),
            // The host runs Incus; Node 209 reaches the builder through it. The builder's address and host key come from the host during the run.
            'jump' => $this->keys->forNode($node),
            'ssh' => ['identity_file' => $identity['identity_file'], 'target' => ['host' => '', 'user' => 'orbit', 'port' => 22, 'host_keys' => []]],
            'profile' => 'base', 'image' => true, 'nfs' => null,
            'recipe' => json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR),
            'vp_lock' => VitePlusRuntimeLock::read($recipe->tools['vp']),
            // The Pi build lives in the Gateway home; 209 copies it with the same pinned SSH as every other step.
            'pi' => ['sha256' => $pi->digest, 'source' => ['ssh' => $this->keys->forNode($gateway), 'path' => config()->string('orbit.home').'/sandbox-artifacts/'.$pi->location]],
        ], 'task-vm-image', null, lockKey: OperationStore::lockKey('task_vm_host', $node->id));
    }
}
