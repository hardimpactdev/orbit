<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Admitted container identity and atomic record projection. No Node execution. */
final readonly class DatabaseServerDestroyProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $input = $operation->input;
        $id = $input['process_id'] ?? null;
        $container = $input['container'] ?? null;
        $recordOnly = $input['record_only'] ?? false;
        if ($operation->kind !== 'database_server.destroy' || $operation->target_type !== 'database_server'
            || ($input['server_id'] ?? null) !== $operation->target_id || ! is_bool($recordOnly)
            || ($id === null ? $container !== null : (! is_int($id) || $id < 1 || ! is_string($container)))) {
            $this->invalid();
        }

        $node = Node::query()->findOrFail($operation->node_id);

        return ['intent' => ['process_id' => $recordOnly ? null : $id, 'container' => $recordOnly ? null : $container,
            'ssh' => ['host' => $node->wireguard_ip, 'user' => $node->user,
                'host_keys' => is_string($node->ssh_host_key_type) && is_string($node->ssh_host_key)
                    ? [$node->ssh_host_key_type.' '.$node->ssh_host_key] : []]], 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $raw = ValidatedData::object($data['result'] ?? []);
        $result = ($raw['backend'] ?? null) === 'absent' ? ['backend' => 'absent'] : [];
        if (is_string($raw['error_code'] ?? null) && preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $raw['error_code']) === 1) {
            $result['error_code'] = $raw['error_code'];
        }
        $status = ValidatedData::string($data['status']);

        return DB::transaction(function () use ($operation, $data, $result, $status): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $input = $operation->input;
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $status === 'succeeded') {
                DatabaseServer::query()->whereKey($operation->target_id)->delete();
                if (! ($input['record_only'] ?? false)) {
                    Process::query()->whereKey($input['process_id'])->delete();
                }
            } elseif (! $terminal && in_array($status, ['failed', 'cancelled'], true)) {
                DatabaseServer::query()->whereKey($operation->target_id)->update([
                    'status' => 'failed', 'error_code' => $data['error_code'] ?? null, 'failed_step' => 'database-apply',
                ]);
            }

            return $projected;
        });
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('database.server_intent_invalid', 'Server removal does not match its admitted Node and Process.', 422);
    }
}
