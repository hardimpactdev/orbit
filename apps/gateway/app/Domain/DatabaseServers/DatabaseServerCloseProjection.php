<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampScheduleWorkflows;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

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

        $password = ValidatedData::string($input['password']);
        $copy = static fn (string $step, string $commands): array => app(SwampCommandStep::class)->executionCopy(
            'orbit-control-inputs', 'database-server-close-intent-'.$id.'-'.$step, 'AUTH default '.$password."\n".$commands);

        // Each value is one valkey-cli call's whole stdin: the AUTH line, then that step's commands.
        return ['intent' => ['host' => $input['host'], 'port' => $input['port'], 'default_open' => $input['default_open'],
            'ssh' => ['host' => $node->wireguard_ip, 'user' => $node->user, 'host_keys' => is_string($node->ssh_host_key_type) && is_string($node->ssh_host_key) ? [$node->ssh_host_key_type.' '.$node->ssh_host_key] : []]],
            'execution_secrets' => [
                'check' => $copy('check', "CLIENT LIST USER default\n"),
                'close' => $copy('close', 'ACL SETUSER default resetpass #'.hash('sha256', $password)."\nACL SAVE\nCLIENT KILL USER default SKIPME yes\n"),
            ]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = ValidatedData::string($data['status']);
        $failure = is_array($data['failure'] ?? null) ? $data['failure'] : [];
        if ($status === 'failed') {
            $data['error_code'] = ($failure['failed_step'] ?? null) === 'Prove no other client' ? 'database.server_in_use' : 'database.server_command_failed';
        }
        $result = $status === 'succeeded' ? ['default_open' => false] : ($status === 'failed' ? ['error_code' => $data['error_code']] : []);

        $projected = DB::transaction(function () use ($operation, $data, $result, $status): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $status === 'succeeded') {
                DatabaseServer::query()->whereKey($operation->target_id)->update(['default_open' => false]);
            }

            return $projected;
        });
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        if ($projected->status->terminal() && $id !== null) {
            foreach (['check', 'close'] as $step) {
                app(SwampScheduleWorkflows::class)->deleteSecret('orbit-control-inputs', 'database-server-close-intent-'.$id.'-'.$step);
            }
        }

        return $projected;
    }
}
