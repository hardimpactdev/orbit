<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\AttachInstanceNodeServiceTargetAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\InstanceNodeServiceTarget;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Admission and projection only; the shared Ansible phase owns every machine change. */
final readonly class NodeServiceTargetOperation implements DirectOperationProjection
{
    public function start(Instance $instance, InstanceNodeServiceTarget $target, ?string $caller = null, ?string $requestId = null): Operation
    {
        $runtime = $instance->runtime()->firstOrFail();
        if ($runtime->power_state !== InstanceRuntimePowerState::Running) {
            throw new ResourceOperationException('process.target_inactive', 'The container is stopped; attachment changes do not start it.', 409);
        }
        $gateway = Ipv4Subnet::from(ValidatedData::string($instance->node->instance_bridge))->usableRange()[0];
        // Remote addresses come from the runtime's frozen admission; local targets
        // always use their recorded port on this immutable Instance bridge.
        $records = $instance->nodeServiceTargets()->with('process')->orderBy('id')->get();
        $remoteRecords = $records->filter(static fn (InstanceNodeServiceTarget $row): bool => $row->node_id !== $instance->node_id);
        $captured = [];
        if ($remoteRecords->isNotEmpty()) {
            $admission = Operation::query()->findOrFail($runtime->operation_id);
            $containerIntent = ValidatedData::object($admission->input['container']);
            $services = $containerIntent['node_services'];
            assert(is_array($services));
            $captured = array_map(ValidatedData::object(...), $services);
        }
        $applied = [];
        foreach ($records as $record) {
            $local = $record->node_id === $instance->node_id;
            $endpoint = $local ? ['host' => $gateway, 'port' => $record->port, 'process_name' => $record->process->name]
                : ValidatedData::object(array_find($captured, static fn (array $row): bool => $row['id'] === $record->id));
            $applied[] = ['id' => $record->id, 'process_id' => $record->process_id,
                'process_name' => ValidatedData::string($endpoint['process_name']), 'host' => ValidatedData::string($endpoint['host']),
                'port' => ValidatedData::integer($endpoint['port']), 'docker' => $local,
                'host_env_key' => $record->host_env_key, 'port_env_key' => $record->port_env_key];
        }
        $removed = ValidatedData::object(array_find($applied,
            static fn (mixed $row): bool => ValidatedData::object($row)['id'] === $target->id));
        $endpoints = array_values(array_filter($applied, static fn (array $row): bool => $row['id'] !== $target->id));
        $policy = app(InstanceContainer::class);
        $remote = $removed['docker'] === false;
        $host = ValidatedData::string($removed['host']);
        $shared = array_any($endpoints, static fn (array $row): bool => $row['host'] === $host && $row['port'] === $target->port);
        $container = ['name' => $runtime->container_name, 'project' => $runtime->incus_project,
            'acl' => $runtime->container_name, 'network' => InstanceHostIntent::Network,
            'bridge_cidr' => $instance->node->instance_bridge, 'bridge_admitted_at' => (int) now()->format('Uu'),
            'node_services' => $endpoints, 'node_service_withdraw' => true,
            'environment' => array_map(static fn (string $key, string $value): string => $key.'='.$value,
                array_keys($proxy = InstanceNodeServiceTargets::proxyEnvironment($gateway, $endpoints, $runtime->incus_project === InstancePlacement::NestedProject, [])),
                $proxy),
            'node_service_removed_ports' => $remote ? [] : [$target->port],
            ...($remote ? [
                'node_service_removed_hosts' => [$host],
                'node_service_withdraw_endpoint' => ['host' => $host, 'port' => $target->port, 'docker' => false],
                'node_service_removed_rules' => $shared ? [] : [$policy->tcpRule($instance, $runtime, $host, $target->port, false,
                    'node-service-'.$host.'-'.$target->port)],
            ] : [])];
        $attachment = AttachInstanceNodeServiceTargetAction::data($target, true)->toArray();

        return app(StartOperationAction::class)->execute('instance.node_service_detach',
            'instance', $instance->id, $instance->node_id, ['container' => $container, 'attachment' => $attachment],
            $caller, $requestId, lockKey: OperationStore::lockKey('instance', $instance->id));
    }

    public function input(Operation $operation): array
    {
        $instance = Instance::query()->with('node')->findOrFail($operation->target_id);

        return ['intent' => [...$operation->input, 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node)]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $result = is_array($data['result'] ?? null) ? $data['result'] : [];
            $attachment = ValidatedData::object($operation->input['attachment']);
            $result = [...$attachment, ...$result,
                'detached' => ($data['status'] ?? null) === 'succeeded'];
            $operation = app(DirectOperation::class)->project($operation,
                [...$data, 'error_code' => $result['error_code'] ?? $data['error_code'] ?? null], $result);
            if ($operation->kind === 'instance.node_service_detach' && $operation->status === OperationStatus::Succeeded) {
                InstanceNodeServiceTarget::query()->where('instance_id', $operation->target_id)
                    ->whereKey(ValidatedData::integer($attachment['id']))->delete();
            }

            return $operation;
        });
    }
}
