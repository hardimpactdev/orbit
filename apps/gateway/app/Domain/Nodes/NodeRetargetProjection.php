<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class NodeRetargetProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['intent'], 'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $terminal = $operation->status->terminal();
            $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['retargeted', 'public_key', 'error_code', 'restored']));
            $operation = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $operation->status->value === 'succeeded') {
                $node = Node::query()->lockForUpdate()->findOrFail($operation->target_id);
                $intent = ValidatedData::object($operation->input['intent']);
                $candidate = ValidatedData::object($intent['candidate']);
                if (isset($result['public_key'])) {
                    $key = ValidatedData::string($result['public_key']);
                    app(VpnConfigurationRepository::class)->assignPeerKey($node, $key,
                        function () use ($node, $candidate, $key): void {
                            $node->update([...$candidate, 'wireguard_public_key' => $key]);
                        });
                } else {
                    $node->update($candidate);
                }
                $prior = ValidatedData::object($intent['prior']);
                app(KnownHostsStore::class)->put($node->public_ssh_host, $node->public_ssh_port,
                    new HostKey(ValidatedData::string($prior['ssh_host_key_type']), ValidatedData::string($prior['ssh_host_key']),
                        ValidatedData::string($prior['ssh_host_fingerprint'])));
                app(RecordEventBroadcaster::class)->broadcast(RecordEventType::NodeUpdated, $node->id, NodeData::fromModel($node->load('roles'))->toArray());
            }

            return $operation;
        });
    }
}
