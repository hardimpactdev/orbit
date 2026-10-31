<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class DatabaseWriteProjection implements DirectOperationProjection
{
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
