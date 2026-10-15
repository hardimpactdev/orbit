<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmHost;
use App\Models\Node;
use App\Models\TaskVm;

/** Removes legacy claim volumes after their VM is gone; never attaches or changes a running workspace. */
final readonly class TaskVmWorkspaceVolumes
{
    public function __construct(private IncusHost $incus) {}

    public function release(TaskVmHost $host, Node $node, TaskVm $vm): void
    {
        $this->delete($host, $node, $vm->name.'-workspace');
    }

    /** @return list<string> */
    public function sweepClones(TaskVmHost $host, Node $node): array
    {
        $live = TaskVm::query()->live()->where('host_node_id', $node->id)->pluck('name')->all();
        $deleted = [];
        foreach ($this->names($host, $node) as $name) {
            if (preg_match('/\A(tvm-[0-9]+)-workspace\z/D', $name, $match) === 1 && ! in_array($match[1], $live, true)) {
                $this->delete($host, $node, $name);
                $deleted[] = $name;
            }
        }

        return $deleted;
    }

    /** @return list<string> */
    private function names(TaskVmHost $host, Node $node): array
    {
        $subject = "the storage volumes of Node [{$node->name}]";
        $result = $this->incus->run($host, $node, ['storage', 'volume', 'list', '--format', 'json', '--', $host->pool], timeout: 60.0);
        $list = IncusHost::json($subject, $result->succeeded() ? $result : throw IncusHost::failed($subject, 'storage volume list', $result));
        if (! array_is_list($list)) {
            throw IncusHost::invalid($subject, 'the volume list is not a JSON list');
        }
        $names = [];
        foreach ($list as $volume) {
            if (! is_array($volume) || ! is_string($volume['name'] ?? null) || ! is_string($volume['type'] ?? null)) {
                throw IncusHost::invalid($subject, 'a volume has no name or type');
            }
            if ($volume['type'] === 'custom') {
                $names[] = $volume['name'];
            }
        }

        return $names;
    }

    private function delete(TaskVmHost $host, Node $node, string $name): void
    {
        $result = $this->incus->run($host, $node, ['storage', 'volume', 'delete', '--', $host->pool, $name], timeout: 120.0);
        if (! $result->succeeded() && in_array($name, $this->names($host, $node), true)) {
            throw IncusHost::failed("the storage volume [{$name}]", 'storage volume delete', $result);
        }
    }
}
