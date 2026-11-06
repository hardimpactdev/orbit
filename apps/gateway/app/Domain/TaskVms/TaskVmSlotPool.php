<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Actions\TaskVms\RecordTaskVmAction;
use App\Actions\TaskVms\StartTaskVmDestroyAction;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\PendingSshHostKey;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * The ready slots of one task VM host (docs/cli/tasks/vm-slots.mmd): stopped task VMs, enrolled ahead of time for one
 * Project and size. {@see self::refill()} is the one rule that keeps the pool: it enrols a slot while the pool is below
 * its count, else destroys one stale slot. The Gateway runs it after every enrol, claim, destroy, image promotion and
 * settings change; each run starts at most one Operation, and the end of that Operation runs it again.
 */
final readonly class TaskVmSlotPool
{
    public const string EnrolKind = 'task_vm.slot_enrol';

    /** A host whose last enrol failed enrols again only this long after the failure. */
    public const int RetryMinutes = 15;

    /** A slot enrolled longer ago than this is stale: the ready copies of its seed expire after 20 hours. */
    public const int MaxAgeHours = 20;

    public function __construct(
        private TaskVmSettings $settings,
        private TaskVmCapacity $capacity,
        private RecordTaskVmAction $record,
        private TaskVmPromotedImage $images,
        private TaskVmImageInputs $inputs,
        private TaskVmProvisionIntent $provision,
        private TaskVmConvergeIntent $converge,
        private TaskVmEnrollmentIntent $enrollment,
        private StartOperationAction $start,
        private StorageRootResolver $roots,
        private NodeSettingsNormalizer $nodeSettings,
    ) {}

    /** Runs the refill rule of the host; returns the Operation it started, or null when the pool needs nothing now. */
    public function refill(int $hostNodeId): ?Operation
    {
        return $this->enrollment->admit(fn (): ?Operation => DB::transaction(function () use ($hostNodeId): ?Operation {
            // Serialize with placement, the claim and image promotion.
            DB::table('task_vm_image_recipe')->where('id', 1)->update(['generation' => DB::raw('generation')]);
            [$step, $vm] = $this->next($hostNodeId) ?? [null, null];

            return match ($step) {
                'enrol' => $this->enrol($hostNodeId),
                'destroy' => $vm instanceof TaskVm ? $this->destroy($vm) : null,
                default => null,
            };
        }));
    }

    /**
     * The one step the pool of the host needs now: `['enrol', null]`, `['destroy', $slot]`, or null. Nothing while a slot
     * of the host enrols or is destroyed; a failed slot is destroyed first; then an enrol while the pool is below its
     * count; then one stale or extra slot is destroyed.
     *
     * @return array{0:'enrol'|'destroy',1:TaskVm|null}|null
     */
    public function next(int $hostNodeId): ?array
    {
        $host = Node::query()->find($hostNodeId);
        if (! $this->settings->enabled || ! $this->settings->hasHost($hostNodeId) || ! $host instanceof Node) {
            return null;
        }
        $location = $this->settings->host($hostNodeId);
        $setting = $location->slots;
        $slots = TaskVm::query()->unclaimedSlots()->where('host_node_id', $hostNodeId)->lockForUpdate()->orderBy('id')->get();
        $failed = $slots->first(static fn (TaskVm $vm): bool => $vm->state === TaskVmState::Failed);
        if ($failed instanceof TaskVm) {
            return ['destroy', $failed];
        }
        if ($slots->contains(static fn (TaskVm $vm): bool => $vm->state !== TaskVmState::Parked)) {
            return null;
        }
        $current = $this->currentImage($host, $location);
        $count = $setting === null ? 0 : $setting->count;
        if ($setting !== null && $slots->count() < $count) {
            $free = $this->capacity->freeSlots([[$location, $host]])[$host->id] ?? 0;

            return $current !== null && Project::query()->whereKey($setting->projectId)->exists()
                && $free >= $setting->size->slots() && ! $this->recentlyFailed($host->id) ? ['enrol', null] : null;
        }
        $stale = $slots->first(fn (TaskVm $vm): bool => $this->stale($vm, $setting, $current))
            ?? ($slots->count() > $count ? $slots->first() : null);

        return $stale instanceof TaskVm ? ['destroy', $stale] : null;
    }

    /**
     * Whether a ready slot can serve a claim now: its Project and size are the setting's, its image is the host's last
     * promoted image, and it is younger than {@see self::MaxAgeHours}.
     *
     * @param  array{build_id:int,fingerprint:string,recipe_identity:string}|null  $current
     */
    public function stale(TaskVm $vm, ?TaskVmSlotSetting $setting, ?array $current): bool
    {
        return $setting === null || $current === null || $vm->slot_project_id !== $setting->projectId || $vm->size !== $setting->size
            || $vm->image_build_id !== $current['build_id'] || $vm->ready_at === null || $vm->ready_at->lt(now()->subHours(self::MaxAgeHours));
    }

    /** @return array{build_id:int,fingerprint:string,recipe_identity:string}|null the host's last promoted image */
    public function currentImage(Node $host, TaskVmHost $location): ?array
    {
        try {
            $image = $this->images->forHost($host, $location);
        } catch (ResourceOperationException) {
            return null;
        }
        $build = $this->images->latest($host);

        return $build === null ? null : ['build_id' => $build->id, ...$image];
    }

    /** The checkout path of a Project's workspace on a task VM: `<apps root>/<project slug>/workspace`, as `tasks:provision` names it. */
    public function checkoutPath(Node $guest, Project $project): string
    {
        $account = new ManagedUserAccount($guest->user, $guest->user, '/home/'.$guest->user);

        return $this->roots->resolveApps($this->nodeSettings->fromStored($guest->settings), $account)
            ->append($project->slug)->append(TaskVmWorkspaceGuard::WorkspaceCheckout)->value;
    }

    /** The digest of what a task image carries in the release this Gateway runs: playbooks, pins and lock files. */
    public function releaseDigest(): string
    {
        return $this->inputs->digest(dirname(base_path(), 2));
    }

    private function enrol(int $hostNodeId): ?Operation
    {
        $host = Node::query()->findOrFail($hostNodeId);
        $location = $this->settings->host($hostNodeId);
        $setting = $location->slots;
        $current = $this->currentImage($host, $location);
        $project = $setting === null ? null : Project::query()->find($setting->projectId);
        if ($setting === null || $current === null || ! $project instanceof Project) {
            return null;
        }
        $vm = $this->record->execute(null, TaskVmPlacementTarget::incusHost($host->id), $setting->size, $project->id);
        $vm->update(['image_fingerprint' => $current['fingerprint'], 'recipe_identity' => $current['recipe_identity'],
            'image_build_id' => $current['build_id'], 'image_fallback' => false, 'slot_digest' => $this->releaseDigest()]);
        $input = $this->provision->capture($vm);
        $guest = $vm->refresh()->node;
        assert($guest instanceof Node);
        // "Converge once" of the enrol: the task VM role phases, with the nested network and the NFS export of the Project's checkout path.
        $input['converge'] = app(PendingSshHostKey::class)->during($guest->id, fn (): array => $this->converge->provisioning($vm,
            ['checkout' => $this->checkoutPath($guest, $project), 'subnet' => TaskVmReadyCopiesIntent::Subnet, 'interface' => TaskVmReadyCopiesIntent::Network]));

        return $this->start->execute(self::EnrolKind, 'task_vm', $vm->id, ValidatedData::integer($input['node_id']), $input, 'task-vm-slots', null);
    }

    private function destroy(TaskVm $vm): ?Operation
    {
        try {
            return app(StartTaskVmDestroyAction::class)->execute($vm->id);
        } catch (ResourceOperationException $refused) {
            report($refused);

            return null;
        }
    }

    private function recentlyFailed(int $hostNodeId): bool
    {
        return Operation::query()->where('kind', self::EnrolKind)->where('node_id', $hostNodeId)
            ->whereIn('status', [OperationStatus::Failed->value, OperationStatus::Interrupted->value])
            ->where('finished_at', '>=', now()->subMinutes(self::RetryMinutes))->exists();
    }
}
