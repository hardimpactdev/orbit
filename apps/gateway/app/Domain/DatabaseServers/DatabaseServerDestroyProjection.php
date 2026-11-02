<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** The admitted container identity as run inputs, and the record deletion from the terminal result. */
final readonly class DatabaseServerDestroyProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $input = $operation->input;
        if ($operation->kind !== 'database_server.destroy' || $operation->target_type !== 'database_server'
            || ($input['server_id'] ?? null) !== $operation->target_id
            || ! is_int($input['process_id'] ?? null) || ! is_string($input['container'] ?? null)) {
            $this->invalid();
        }

        return ['intent' => ['container' => $input['container'],
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id))],
            'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        // Native result (no report step): the failed step names the error code.
        $status = ValidatedData::string($data['status']);
        $failed = ValidatedData::stringList(ValidatedData::object(ValidatedData::object($data['result'] ?? [])['summary'] ?? [])['failed_steps'] ?? []);
        $failedStep = $failed[0] ?? null;
        $result = $status === 'succeeded' ? ['backend' => 'absent']
            : ($failedStep === null ? [] : ['error_code' => $failedStep === 'Check it is gone' ? 'database.server_remove_incomplete' : 'database.server_remove_failed']);
        $data = [...$data, 'error_code' => $result['error_code'] ?? $data['error_code'] ?? null];

        return DB::transaction(function () use ($operation, $data, $result, $status, $failedStep): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $status === 'succeeded') {
                DatabaseServer::query()->whereKey($operation->target_id)->delete();
                Process::query()->whereKey($operation->input['process_id'])->delete();
            } elseif (! $terminal && in_array($status, ['failed', 'cancelled', 'interrupted'], true)) {
                DatabaseServer::query()->whereKey($operation->target_id)->update([
                    'status' => 'failed', 'error_code' => $data['error_code'] ?? $result['error_code'] ?? null, 'failed_step' => $failedStep,
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
