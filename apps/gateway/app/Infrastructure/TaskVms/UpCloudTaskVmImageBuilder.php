<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmCapabilities;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Models\Node;
use App\Models\Operation;
use App\Models\UpCloudTaskVmImageBuild;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Admission only. Swamp builds and verifies every template. */
final readonly class UpCloudTaskVmImageBuilder
{
    public function __construct(private RecipeImageBuilds $builds, private StartOperationAction $operations,
        private RecipeImageInput $recipes, private TaskVmSettings $settings) {}

    public function build(?MachineRecipe $recipe = null, ?int $afterAttemptId = null, ?int $generation = null): Operation
    {
        TaskVmCapabilities::require('children');
        TaskVmCapabilities::require('receipts');
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
                'recipe' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'recipe_json' => $recipe->json,
                'deadline_at' => now()->addSeconds(UpCloudTaskVmImageBuild::RunningSeconds)->getTimestamp()];
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
