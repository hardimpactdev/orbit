<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class DatabaseWriteProjection implements DirectOperationProjection
{
    /** Full native inputs; the shared start path owns submission and terminal subscription.
     * @return array<string, mixed>
     */
    public function workflowInputs(Operation $operation): array
    {
        $intent = DatabaseExecutionIntent::recorded(app(DatabaseIntentSource::class)->ownerId($operation));
        $material = array_intersect_key($intent->payload['commands']['database-apply']['input'], array_flip(['password', 'sql', 'dsn', 'username']));

        return [
            'operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind,
            'intent' => ['connection_id' => $operation->input['connection_id'], ...$this->input($operation)],
            'execution_secrets' => ['database_write' => app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'database-write-intent-'.$intent->id, json_encode($material, JSON_THROW_ON_ERROR))],
        ];
    }

    public function input(Operation $operation): array
    {
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        $intent = $id === null ? null : DatabaseExecutionIntent::recorded($id);
        $command = $intent?->payload['commands']['database-apply'] ?? [];
        $input = $command['input'] ?? [];
        $driver = $operation->input['driver'] ?? null;
        if ($operation->kind !== 'database.write' || $operation->target_type !== 'database_connection' || $intent === null
            || ! in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            $this->invalid();
        }
        if ($driver === 'sqlite') {
            return ['driver' => $driver, 'owner' => ValidatedData::string($input['owner']), 'path' => ValidatedData::string($input['path'])];
        }

        return ['driver' => $driver];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $raw = ValidatedData::object($data['result'] ?? []);
        $result = [];
        if (in_array($raw['backend'] ?? null, ['active', 'absent'], true)) {
            $result['backend'] = $raw['backend'];
        }
        if (is_string($raw['error_code'] ?? null) && preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $raw['error_code']) === 1) {
            $result['error_code'] = $raw['error_code'];
        }
        if (is_int($raw['row_count'] ?? null) && $raw['row_count'] >= 0) {
            $result['row_count'] = $raw['row_count'];
        }
        if (is_bool($raw['truncated'] ?? null)) {
            $result['truncated'] = $raw['truncated'];
        }

        return app(DirectOperation::class)->project($operation, $data, $result);
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('database.write_intent_invalid', 'The write does not match its admitted Connection and Node.', 422);
    }
}
