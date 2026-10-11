<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Node;
use App\Models\TaskVm;

/**
 * Compares the instances of a task VM host's Incus project with the task VM rows of that host. The sweep job
 * acts on the result, and Doctor reports it.
 *
 * - An orphaned VM is a `tvm-<number>` instance with no live row of that name on the host. Other names,
 *   such as the base image builder `tvm-image-<time>`, are never Orbit's task VMs and stay untouched.
 * - A missing VM is a `ready` or `parked` row whose instance is gone. Other states have jobs that check the VM.
 */
final readonly class TaskVmHostAudit
{
    private const string TaskVmName = '/\Atvm-[0-9]+\z/D';

    public function __construct(private IncusHost $incus) {}

    /** @return array{orphaned: list<string>, missing: list<TaskVm>} */
    public function audit(TaskVmHost $host, Node $node): array
    {
        $names = $this->incus->instanceNames($host, $node);
        $live = TaskVm::query()->live()->where('host_node_id', $node->id)->get();
        $recorded = $live->pluck('name')->all();

        return [
            'orphaned' => array_values(array_filter(
                $names,
                static fn (string $name): bool => preg_match(self::TaskVmName, $name) === 1 && ! in_array($name, $recorded, true),
            )),
            'missing' => array_values($live->filter(
                static fn (TaskVm $vm): bool => in_array($vm->state, [TaskVmState::Ready, TaskVmState::Parked], true) && ! in_array($vm->name, $names, true),
            )->all()),
        ];
    }

    /** Deletes an orphaned VM from the host's project. An absent VM counts as deleted. */
    public function deleteOrphan(TaskVmHost $host, Node $node, string $name): void
    {
        if (preg_match(self::TaskVmName, $name) !== 1) {
            return;
        }
        $result = $this->incus->run($host, $node, ['delete', '--force', '--', $name]);
        if (! $result->succeeded() && in_array($name, $this->incus->instanceNames($host, $node), true)) {
            throw IncusHost::failed("task VM [{$name}]", 'delete', $result);
        }
    }
}
