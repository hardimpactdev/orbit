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
        // Withdrawal uses recorded identities, not live Docker eligibility: a stopped target must remain detachable.
        $endpoints = $instance->nodeServiceTargets()->where('id', '!=', $target->id)->orderBy('id')->get()
            ->map(static fn (InstanceNodeServiceTarget $row): array => ['id' => $row->id, 'host' => $gateway, 'port' => $row->port,
                'docker' => true, 'host_env_key' => $row->host_env_key, 'port_env_key' => $row->port_env_key])->all();
        $container = ['name' => $runtime->container_name, 'project' => $runtime->incus_project,
            'acl' => $runtime->container_name, 'network' => InstanceHostIntent::Network,
            'bridge_cidr' => $instance->node->instance_bridge, 'bridge_admitted_at' => (int) now()->format('Uu'),
            'node_services' => $endpoints, 'node_service_withdraw' => true,
            'node_service_removed_ports' => [$target->port]];
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
