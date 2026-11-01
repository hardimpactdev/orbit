<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Projects the native run; descriptors contain paths and placement, never database bytes. */
final readonly class DatabaseSqliteProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function input(Operation $operation): array
    {
        $intent = $this->publicDescriptors($operation);
        $intent['source_ssh'] = $this->ssh(Node::query()->findOrFail(ValidatedData::integer(ValidatedData::object($intent['source'] ?? [])['node_id'] ?? null)));
        if (isset($intent['target'])) {
            $intent['target_ssh'] = $this->ssh(Node::query()->findOrFail(ValidatedData::integer(ValidatedData::object($intent['target'])['node_id'] ?? null)));
        }

        return ['intent' => $intent, 'execution_secrets' => []];
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        if (data_get($operation->input, 'source.node_id') !== $callerNodeId
            && data_get($operation->input, 'target.node_id') !== $callerNodeId) {
            throw new ResourceOperationException('operation.executor_forbidden', 'This Node is not an admitted SQLite source or target.', 403);
        }
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $this->assertPublicParticipant($operation, $callerNodeId);

        return $this->publicDescriptors($operation);
    }

    /** @return array<string,mixed> */
    private function publicDescriptors(Operation $operation): array
    {
        return array_intersect_key($operation->input, array_flip(['source', 'target']));
    }

    /** @return array{host:string,user:string,host_keys:list<string>} */
    private function ssh(Node $node): array
    {
        if (! is_string($node->wireguard_ip) || filter_var($node->wireguard_ip, FILTER_VALIDATE_IP) === false) {
            throw new ResourceOperationException('database.sqlite_intent_invalid', 'The recorded SQLite Node has no address.', 422);
        }

        return ['host' => $node->wireguard_ip, 'user' => $node->user,
            'host_keys' => is_string($node->ssh_host_key_type) && is_string($node->ssh_host_key)
                ? [$node->ssh_host_key_type.' '.$node->ssh_host_key] : []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['backend', 'error_code', 'bytes', 'sha256', 'mode', 'state']));

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
