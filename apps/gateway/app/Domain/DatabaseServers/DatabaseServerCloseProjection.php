<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class DatabaseServerCloseProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        $intent = $id === null ? null : DatabaseExecutionIntent::recorded($id);
        $command = $intent?->payload['commands']['database-apply'] ?? [];
        $input = $command['input'] ?? [];
        if ($operation->kind !== 'database_server.close' || $operation->target_type !== 'database_server'
            || $intent === null
            || ! is_bool($input['default_open'] ?? null) || ($input['action'] ?? null) !== ($input['default_open'] ? 'close' : 'ready')) {
            throw new ResourceOperationException('database.server_intent_invalid', 'Closure does not match its admitted Database server.', 422);
        }

        $node = Node::query()->findOrFail($operation->node_id);

        return ['intent' => ['host' => $input['host'], 'port' => $input['port'], 'default_open' => $input['default_open'],
            'ssh' => ['host' => $node->wireguard_ip, 'user' => $node->user, 'host_keys' => is_string($node->ssh_host_key_type) && is_string($node->ssh_host_key) ? [$node->ssh_host_key_type.' '.$node->ssh_host_key] : []]],
            'execution_secrets' => ['server_close' => app(SwampCommandStep::class)->executionCopy(
                'orbit-control-inputs', 'database-server-close-intent-'.$id, json_encode(['password' => $input['password']], JSON_THROW_ON_ERROR),
            )]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['backend', 'error_code', 'default_open']));
        $status = ValidatedData::string($data['status']);
        $unknown = $result === ['error_code' => 'database.server_restore_failed', 'default_open' => 'unknown'];

        return DB::transaction(function () use ($operation, $data, $result, $status, $unknown): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && in_array($status, ['failed', 'cancelled', 'interrupted'], true) && $unknown) {
                DatabaseServer::query()->whereKey($operation->target_id)->update(['default_open' => null, 'status' => 'failed', 'error_code' => 'database.default_access_unknown', 'failed_step' => 'database-apply']);
                Log::critical('Database default access is unknown after ACL restore failed.', ['operation_id' => $operation->id, 'database_server_id' => $operation->target_id, 'node_id' => $operation->node_id]);
            }
            if (! $terminal && $status === 'succeeded') {
                DatabaseServer::query()->whereKey($operation->target_id)->update(['default_open' => false]);
            }

            return $projected;
        });
    }
}
