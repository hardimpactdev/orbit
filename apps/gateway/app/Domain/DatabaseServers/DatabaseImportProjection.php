<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseExecutionIntent;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** database:import: the owner's password line goes Gateway → vault → stdin; the result is counts, never names or data. */
final readonly class DatabaseImportProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $intent = $this->intent($operation);
        $command = $intent->payload['commands']['database-apply']['input'];
        $secret = ['vault' => 'orbit-control-inputs', 'key' => 'database-import-intent-'.$intent->id];
        if (! $operation->status->terminal()) {
            $secret = app(SwampCommandStep::class)->executionCopy($secret['vault'], $secret['key'], ValidatedData::string($command['password'])."\n");
        }

        return ['intent' => ['argv' => ValidatedData::stringList($command['argv']),
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id))],
            'execution_secrets' => ['import' => $secret]];
    }

    /** The script's one result line decides; a run that ended without it (a timeout, a lost SSH) leaves the state unknown. */
    public function apply(Operation $operation, array $data): Operation
    {
        $this->intent($operation);
        $status = $data['status'] ?? null;
        if (! in_array($status, ['succeeded', 'failed'], true)) {
            return app(DirectOperation::class)->project($operation, $data, []);
        }
        $captured = ValidatedData::object($data['result'] ?? []);
        $output = $status === 'succeeded' ? (ValidatedData::nullableString($captured['output'] ?? null) ?? '') : '';
        ['error_code' => $code, 'result' => $result] = DatabaseImport::outcome($output);
        if ($code !== null) {
            $result = ['error_code' => $code, ...$result];
            $data = [...$data, 'status' => 'failed', 'error_code' => $code,
                'failure' => ['failed_step' => 'Load the dump', 'stderr_recorded' => false]];
        }

        return app(DirectOperation::class)->project($operation, $data, $result);
    }

    private function intent(Operation $operation): DatabaseExecutionIntent
    {
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        if ($operation->kind !== 'database.import' || $operation->target_type !== 'database_connection' || $id === null) {
            throw new ResourceOperationException('database.import_intent_invalid', 'The import does not match its admitted Connection and Node.', 422);
        }

        return DatabaseExecutionIntent::recorded($id);
    }
}
