<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskWait;
use App\Domain\WireGuard\WireGuardAddressAllocator;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The slot identities of one task VM host, `<host>-tvm-1` to `<host>-tvm-<count>` (docs/cli/tasks/vm-slot-enrol.mmd). A slot is only a
 * fixed identity, a permanent Node with its keys and hub peer; a task start makes a VM with it (docs/cli/tasks/provision.mmd) and a
 * release deletes that VM (docs/cli/tasks/cancel.mmd). {@see self::refill()} is the one rule that keeps the identities: it enrols a
 * missing or failed one, and releases a failed one that still holds a model key first. Each run starts at most one; its end runs the rule again.
 */
final readonly class TaskVmSlotPool
{
    public const string EnrolKind = 'task_vm.slot_enrol';

    public const string ReleaseKind = 'task_vm.slot_release';

    public function __construct(
        private TaskVmSettings $settings,
        private TaskVmPromotedImage $images,
        private TaskVmSlotEnrolIntent $enrol,
        private TaskVmEnrollmentIntent $enrollment,
        private TaskVmSlotRuns $runs,
        private StartOperationAction $start,
        private WireGuardAddressAllocator $addresses,
    ) {}

    /** Runs the rule of the host; returns the enrol it started, or null when the slots need nothing now. */
    public function refill(int $hostNodeId): ?Operation
    {
        if (! $this->settings->enabled || ! $this->settings->hasHost($hostNodeId) || $this->settings->host($hostNodeId)->slots === null) {
            return null;
        }

        return $this->enrollment->admit(fn (): ?Operation => DB::transaction(function () use ($hostNodeId): ?Operation {
            $next = $this->next($hostNodeId);

            if ($next === null) {
                return null;
            }
            $slot = $next['slot'];

            // A failed release can leave a model key that the router still accepts: a release revokes it before the slot is enrolled again.
            return $slot->state === TaskVmState::Failed && is_string($slot->model_key)
                ? $this->runs->startRelease($slot, 'task-vm-slots')
                : $this->startEnrol($slot);
        }));
    }

    /**
     * The one identity the host needs enrolled or released now, or null. Nothing while a free identity enrols or releases; a missing one
     * comes first, then a failed one.
     *
     * @return array{slot:TaskVm}|null
     */
    public function next(int $hostNodeId): ?array
    {
        $host = Node::query()->find($hostNodeId);
        $setting = $this->settings->host($hostNodeId)->slots;
        if (! $host instanceof Node || $host->status !== LifecycleStatus::Active || $setting === null) {
            return null;
        }
        $slots = self::slots($hostNodeId)->lockForUpdate()->get()->keyBy('slot_number');
        if ($slots->contains(static fn (TaskVm $vm): bool => $vm->group_id === null && in_array($vm->state, [TaskVmState::Provisioning, TaskVmState::Releasing], true))) {
            return null;
        }
        foreach (range(1, self::count($this->settings->host($hostNodeId), $setting)) as $number) {
            if (! $slots->has($number)) {
                return ['slot' => $this->record($host, $setting, $number)];
            }
        }
        $failed = $slots->first(static fn (TaskVm $vm): bool => $vm->group_id === null && $vm->state === TaskVmState::Failed);

        return $failed instanceof TaskVm ? ['slot' => $failed] : null;
    }

    /**
     * Takes a free slot identity for the group in its admission transaction: one of the group's Project, on a host with a promoted image,
     * the host with the most free identities first. `$host` pins the claim to one host; `$keepFree` slots of that host must stay free after it, as a
     * report-only proof asks. The slot holds the group at once.
     *
     * @throws TaskCapacityException when no slot is free
     */
    public function claim(Task $group, ?int $host = null, int $keepFree = 0): TaskVm
    {
        $idle = TaskVm::query()->whereNotNull('slot_number')->whereNull('group_id')->where('state', TaskVmState::Parked)
            ->where('slot_project_id', $group->project_id)->when($host !== null, static fn ($query) => $query->where('host_node_id', $host))
            ->lockForUpdate()->orderBy('slot_number')->get();
        $free = $idle->filter(function (TaskVm $vm): bool {
            $node = $vm->hostNode;
            $current = $node instanceof Node && $this->settings->hasHost($node->id) ? $this->currentImage($node, $this->settings->host($node->id)) : null;

            // A proof takes only a host whose image includes every change of what the image carries up to its merge base.
            return $current !== null && app(TaskVmSeedFloor::class)->allows($node->id);
        });
        $byHost = $free->groupBy('host_node_id')->filter(static fn ($slots): bool => $slots->count() > $keepFree)->sortByDesc(static fn ($slots): int => $slots->count());
        $slot = $byHost->first()?->first();
        if (! $slot instanceof TaskVm) {
            $skipped = $idle->reject(static fn (TaskVm $vm): bool => app(TaskVmSeedFloor::class)->allows((int) $vm->host_node_id))
                ->mapWithKeys(static fn (TaskVm $vm): array => [(int) $vm->host_node_id => (string) $vm->hostNode?->name])->all();
            throw new TaskCapacityException(TaskWait::Capacity, 'Task VM: no slot of this Project is free'.($keepFree > 0 ? " with {$keepFree} more left free" : '').'.'
                .($skipped === [] ? '' : self::staleImages($skipped)));
        }
        $slot->update(['group_id' => $group->id, 'claimed_at' => now(), 'state' => TaskVmState::Provisioning]);

        return $slot;
    }

    /**
     * What a proof needs to be told about the hosts whose image its floor rules out: the changes the image needs, why each host's image lacks
     * them, and whether a refresh of that host is running, so that the caller knows when to run the proof again. A refusal starts no refresh.
     *
     * @param  array<int,string>  $skipped  host Node id => name
     */
    private static function staleImages(array $skipped): string
    {
        $text = ' Hosts with free slots but no usable nested fleet seed are skipped: '.implode(', ', $skipped).'.'
            .' A proof needs an image that includes every change of the playbooks, pins and lock files up to the merge base of its commit.';
        foreach ($skipped as $id => $name) {
            $refresh = TaskVmImageRefreshIntent::open($id);
            $text .= " {$name}: ".(app(TaskVmSeedFloor::class)->reason($id) ?? 'no refresh has promoted a usable image for it').'; '
                .($refresh instanceof Operation ? "a refresh is running (operation {$refresh->id}), run the proof again when it ends." : 'no refresh is running; a release that changes the playbooks, pins or lock files starts one.');
        }

        return $text;
    }

    /** Starts the enrol of the identity `$slot`. */
    public function startEnrol(TaskVm $slot): Operation
    {
        $slot->update(['state' => TaskVmState::Provisioning, 'error_code' => null, 'error_message' => null]);
        $input = $this->enrol->input($slot);

        return $this->start->execute(self::EnrolKind, 'task_vm', $slot->id, (int) $slot->host_node_id, $input, 'task-vm-slots', null);
    }

    /**
     * The identities the host keeps: its `count`, at most as many VMs of the slot size as the host can run at once.
     */
    public static function count(TaskVmHost $location, TaskVmSlotSetting $setting): int
    {
        return min($setting->count, intdiv($location->maxVms, $setting->size->slots()));
    }

    /** @return Builder<TaskVm> the slot rows of a host */
    public static function slots(int $hostNodeId): Builder
    {
        return TaskVm::query()->where('host_node_id', $hostNodeId)->whereNotNull('slot_number')->where('state', '!=', TaskVmState::Destroyed);
    }

    /** @return array{vm:string,seed:string,snapshot:string,recipe_identity:string}|null the base snapshot a task start on the host copies */
    public function currentImage(Node $host, TaskVmHost $location): ?array
    {
        try {
            return $this->images->snapshot($host, $location);
        } catch (ResourceOperationException) {
            return null;
        }
    }

    /** The row of a new slot number, with its fixed name and WireGuard address. Its keys are made by its first enrol. */
    private function record(Node $host, TaskVmSlotSetting $setting, int $number): TaskVm
    {
        return TaskVm::query()->create([
            'slot_project_id' => $setting->projectId, 'slot_number' => $number, 'host_node_id' => $host->id, 'provider' => TaskVmProviderKind::Incus,
            'size' => $setting->size, 'name' => $host->name.'-tvm-'.$number, 'state' => TaskVmState::Provisioning,
            'wireguard_ip' => $this->addresses->nextIn($this->settings->wireguardRange,
                array_values(TaskVm::query()->live()->get()->map(static fn (TaskVm $vm): string => $vm->wireguard_ip)->all())),
        ]);
    }
}
