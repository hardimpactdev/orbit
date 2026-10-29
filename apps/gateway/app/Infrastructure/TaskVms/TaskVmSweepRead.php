<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/** Inventory and ownership only. It never deletes observed resources. */
final readonly class TaskVmSweepRead
{
    public function __construct(private TaskVmSettings $settings, private TaskVmHostAudit $audit, private IncusHost $incus, private UpCloudServers $servers) {}

    /** @return array{deletions:list<array{kind:string,name:string,uuid?:string,label?:string,value?:string}>,missing_ids:list<int>,live_ids:list<int>,server_states?:array<string,string>} */
    public function read(Operation $operation): array
    {
        if ($operation->target_type !== 'task_vm_sweep' || $operation->node_id !== ($operation->input['node_id'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A host sweep Operation is required.', 422);
        }
        $parentId = $operation->getAttribute('parent_operation_id');
        $parent = $parentId === null ? $operation : Operation::query()->findOrFail(ValidatedData::integer($parentId));
        if ($parent->kind !== 'task_vm.sweep_host' || ($operation->input['sweep_id'] ?? null) !== $parent->id || $parent->input['provider'] !== $operation->input['provider']) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The sweep observation does not belong to this parent.', 422);
        }
        $node = Node::query()->findOrFail($operation->node_id);
        if ($operation->input['provider'] === 'incus') {
            $host = $this->settings->host($node->id);
            $found = $this->audit->audit($host, $node);
            $live = TaskVm::query()->live()->where('host_node_id', $node->id)->get();
            $volumes = [];
            foreach ($this->incus->volumeNames($host, $node) as $name) {
                if (preg_match('/\A(tvm-[0-9]+)-topology-seed\z/D', $name, $match) === 1 && ! $live->contains('name', $match[1])) {
                    $volumes[] = $name;
                }
            }
            $strays = $this->audit->strays($host, $node);
            foreach ($strays['instances'] as $name) {
                Log::warning('A task VM host has an Incus instance that Orbit does not own.', ['node_id' => $node->id, 'instance' => $name]);
            }
            foreach ($strays['volumes'] as $name) {
                Log::warning('A task VM host has an Incus custom volume that Orbit does not own.', ['node_id' => $node->id, 'volume' => $name]);
            }
            foreach ($strays['records'] as $vm) {
                Log::warning('A task VM is ready or parked without an open group.', ['task_vm_id' => $vm->id]);
            }
            $deletions = array_map(static fn (string $name): array => ['kind' => 'instance', 'name' => $name], $found['orphaned']);
            foreach ($volumes as $name) {
                $deletions[] = ['kind' => 'volume', 'name' => $name];
            }

            return ['deletions' => $deletions, 'missing_ids' => array_map(static fn (TaskVm $vm): int => $vm->id, $found['missing']), 'live_ids' => array_values($live->map(static fn (TaskVm $vm): int => $vm->id)->all())];
        }
        $labelled = $this->servers->labelled(TaskVmUpCloud::Label);
        $live = TaskVm::query()->live()->where('provider', TaskVmProviderKind::UpCloud)->get();
        $deletions = [];
        foreach ($labelled as $server) {
            if ($server['title'] === 'tvm-'.$server['value'] && preg_match('/\Atvm-[0-9]+\z/D', $server['title']) === 1 && ! $live->contains('name', $server['title'])) {
                $deletions[] = ['kind' => 'server', 'name' => $server['title'], 'uuid' => $server['uuid'], 'label' => TaskVmUpCloud::Label, 'value' => $server['value']];
            }
        }
        foreach ($this->servers->labelled(UpCloudServers::ImageLabel) as $server) {
            if ($server['title'] === 'tvm-image-'.$server['value'] || $server['title'] === 'tvm-image-'.$server['value'].'-smoke') {
                if ($this->stale($server['value'])) {
                    $deletions[] = ['kind' => 'server', 'name' => $server['title'], 'uuid' => $server['uuid'], 'label' => UpCloudServers::ImageLabel, 'value' => $server['value']];
                }
            }
        }
        foreach ($this->servers->templates() as $uuid => $title) {
            if (preg_match('/\A'.preg_quote(TaskVmUpCloud::TemplatePrefix, '/').'([0-9]{14})'.preg_quote(TaskVmUpCloud::Unverified, '/').'\z/D', $title, $match) === 1 && $this->stale($match[1])) {
                $deletions[] = ['kind' => 'template', 'name' => $title, 'uuid' => $uuid];
            }
        }
        $serverStates = [];
        foreach ($deletions as $deletion) {
            if ($deletion['kind'] === 'server') {
                $server = $this->servers->find($deletion['name'], ValidatedData::string($deletion['label']), ValidatedData::string($deletion['value']), ValidatedData::string($deletion['uuid']));
                if ($server !== null) {
                    $serverStates[$server->uuid] = $server->state;
                }
            }
        }
        $names = array_column($labelled, 'title');
        $missing = $live->filter(static fn (TaskVm $vm): bool => in_array($vm->state, [TaskVmState::Ready, TaskVmState::Parked], true) && ! in_array($vm->name, $names, true))->map(static fn (TaskVm $vm): int => $vm->id)->values()->all();

        return ['deletions' => $deletions, 'server_states' => $serverStates, 'missing_ids' => array_values($missing), 'live_ids' => array_values($live->map(static fn (TaskVm $vm): int => $vm->id)->all())];
    }

    private function stale(string $stamp): bool
    {
        if (preg_match('/\A[0-9]{14}\z/D', $stamp) !== 1 || UpCloudTaskVmImageBuilder::running($stamp)) {
            return false;
        }
        $date = Carbon::createFromFormat('YmdHis', $stamp);

        return $date instanceof Carbon && $date->lt(now()->subHours(2));
    }
}
