<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\VitePlusRuntimeLock;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Models\UpCloudTaskVmImageBuild;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Admission only. Swamp builds and verifies every template. */
final readonly class UpCloudTaskVmImageBuilder
{
    public function __construct(private RecipeImageBuilds $builds, private StartOperationAction $operations, private TaskVmSettings $settings, private UpCloudGuest $guest) {}

    public function build(?MachineRecipe $recipe = null, ?int $afterAttemptId = null, ?int $generation = null): Operation
    {
        $recipe ??= $this->builds->currentRecipe();
        $upcloud = $this->settings->upcloud ?? throw new TaskVmException('task_vm.upcloud_not_configured', 'UpCloud is not configured.');
        $node = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
        $pi = $recipe->tools['pi']->artifacts['amd64'] ?? throw new TaskVmException('task_vm.image_build_failed', 'The recipe holds no Pi server for this architecture.');

        return DB::transaction(function () use ($recipe, $afterAttemptId, $generation, $node, $pi, $upcloud): Operation {
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
            // One host key pair pins both build servers; the private key stays encrypted on the attempt until the run start copies it to the vault.
            $bootstrap = $this->guest->generateHostKey('tvm-image-'.$stamp);
            $input = ['build_id' => $build->id, 'node_id' => $node->id, 'identity' => $recipe->identity, 'generation' => (int) $desired->generation,
                'stamp' => $stamp, 'name' => 'tvm-image-'.$stamp, 'source' => $upcloud->sourceTemplate,
                'pi' => ['sha256' => $pi->digest, 'path' => config()->string('orbit.home').'/sandbox-artifacts/'.$pi->location],
                'recipe' => $recipe->json, 'vp_lock' => VitePlusRuntimeLock::read($recipe->tools['vp']), 'host_key' => $bootstrap['public'],
                'upcloud' => ['zone' => $upcloud->zone, 'plan' => $upcloud->plan, 'disk_gb' => $upcloud->diskGb],
                'pinned_templates' => TaskVm::query()->live()->where('provider', TaskVmProviderKind::UpCloud)->whereNull('provider_id')->whereNotNull('image_fingerprint')->pluck('image_fingerprint')->all()];
            $build->forceFill(['intent' => $input, 'resources' => [], 'bootstrap' => $bootstrap])->save();

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
