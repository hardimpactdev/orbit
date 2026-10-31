<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseExecutionIntent;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class DatabaseReadProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $intent = DatabaseExecutionIntent::recorded(app(DatabaseIntentSource::class)->ownerId($operation));
        $admission = $operation->input;
        $node = Node::query()->findOrFail($operation->node_id);
        $input = ['connection_id' => $admission['connection_id'], 'driver' => $admission['driver'], 'mode' => $admission['mode'], 'table' => $admission['table'] ?? '',
            'ssh' => ['host' => $node->wireguard_ip, 'user' => $node->user, 'host_keys' => is_string($node->ssh_host_key_type) && is_string($node->ssh_host_key) ? [$node->ssh_host_key_type.' '.$node->ssh_host_key] : []]];
        if ($admission['driver'] === 'sqlite') {
            $command = $intent->payload['commands']['database-apply']['input'];
            $input += ['owner' => $command['owner'], 'path' => $command['path']];
        }

        $material = array_intersect_key($intent->payload['commands']['database-apply']['input'], array_flip(['password', 'sql', 'dsn', 'username']));

        return ['intent' => $input, 'execution_secrets' => ['database_read' => app(SwampCommandStep::class)->executionCopy(
            'orbit-control-inputs', 'database-read-intent-'.$intent->id, json_encode($material, JSON_THROW_ON_ERROR),
        )]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return app(DirectOperation::class)->project($operation, $data, ValidatedData::object($data['result'] ?? []));
    }
}
