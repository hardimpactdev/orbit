<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Models\UpCloudTaskVmImageBuild;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Admission only. Swamp builds and verifies every template. */
final readonly class UpCloudTaskVmImageBuilder
{
    public function __construct(private RecipeImageBuilds $builds, private StartOperationAction $operations,
        private RecipeImageInput $recipes, private TaskVmSettings $settings, private SshKeyProvider $keys) {}

    public function build(?MachineRecipe $recipe = null, ?int $afterAttemptId = null, ?int $generation = null): Operation
    {
        $recipe ??= $this->builds->currentRecipe();
        $upcloud = $this->settings->upcloud ?? throw new TaskVmException('task_vm.upcloud_not_configured', 'UpCloud is not configured.');
        $node = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
        // This target carries only architecture while compiling public recipe data. It makes no connection.
        $payload = $this->recipes->payload(AgentGuest::incus($node, 'upcloud', 'image-input'), $recipe, 'base');

        return DB::transaction(function () use ($recipe, $afterAttemptId, $generation, $node, $payload, $upcloud): Operation {
            DB::table('task_vm_image_recipe')->where('id', 1)->update(['generation' => DB::raw('generation')]);
            $desired = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();
            if ($desired->identity !== $recipe->identity || ($generation !== null && (int) $desired->generation !== $generation)
                || ($afterAttemptId !== null && self::lastAttempt($recipe->identity) !== $afterAttemptId)) {
                throw new TaskVmException('task_vm.image_build_superseded', 'The desired UpCloud recipe attempt changed.');
            }
            $running = UpCloudTaskVmImageBuild::query()->where('state', 'running')->where('started_at', '>', now()->subSeconds(UpCloudTaskVmImageBuild::RunningSeconds))->exists();
            if ($running) {
                throw new TaskVmException('task_vm.image_build_running', 'An UpCloud image Operation is already running.');
            }
            $build = new UpCloudTaskVmImageBuild;
            $build->forceFill(['recipe_identity' => $recipe->identity, 'state' => 'running', 'started_at' => now()])->save();
            $last = UpCloudTaskVmImageBuild::query()->where('id', '<', $build->id)->whereNotNull('intent')->latest('id')->first();
            $stampTime = now();
            if ($last !== null && $last->intent !== null) {
                $previous = Carbon::createFromFormat('YmdHis', $last->intent['stamp']);
                if ($previous !== null && $previous->greaterThanOrEqualTo($stampTime)) {
                    $stampTime = $previous->addSecond();
                }
            }
            $stamp = $stampTime->format('YmdHis');
            $input = ['build_id' => $build->id, 'node_id' => $node->id, 'identity' => $recipe->identity, 'generation' => (int) $desired->generation,
                'stamp' => $stamp, 'name' => 'tvm-image-'.$stamp, 'source' => $upcloud->sourceTemplate,
                'pi_artifact' => isset($recipe->tools['pi']->artifacts['amd64']) ? ['path' => config()->string('orbit.home').'/sandbox-artifacts/'.$recipe->tools['pi']->artifacts['amd64']->location, 'sha256' => $recipe->tools['pi']->artifacts['amd64']->digest] : null,
                'recipe' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'agent_sha256' => $payload['agent_sha256'], 'gateway_public_key' => $this->keys->publicKey(), 'ssh_identity' => $this->keys->privateKeyPath(),
                'upcloud' => ['zone' => $upcloud->zone, 'plan' => $upcloud->plan, 'disk_gb' => $upcloud->diskGb],
                'upcloud_token' => ['$secret' => 'upcloud.api', 'id' => $build->id],
                'bootstrap' => ['$secret' => 'task_vm.image_bootstrap', 'id' => $build->id],
                'pi_artifact_file' => ['$secret' => 'task_vm.image_artifact', 'id' => $build->id],
                'pinned_templates' => TaskVm::query()->live()->where('provider', TaskVmProviderKind::UpCloud)->whereNull('provider_id')->whereNotNull('image_fingerprint')->pluck('image_fingerprint')->all()];
            $build->forceFill(['intent' => $input, 'resources' => []])->save();

            return $this->operations->execute('task_vm.upcloud_image_build', 'task_vm_upcloud_image', $build->id, $node->id, $input, 'task-vm-image', null);
        });
    }

    public static function lastAttempt(string $identity): int
    {
        $id = UpCloudTaskVmImageBuild::query()->where('recipe_identity', $identity)->max('id');

        return is_numeric($id) ? (int) $id : 0;
    }

    public static function running(string $stamp): bool
    {
        return UpCloudTaskVmImageBuild::query()->where('state', 'running')->where('intent->stamp', $stamp)->where('started_at', '>', now()->subSeconds(UpCloudTaskVmImageBuild::RunningSeconds))->exists();
    }
}
