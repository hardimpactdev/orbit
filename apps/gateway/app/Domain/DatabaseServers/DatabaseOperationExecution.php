<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Actions\DatabaseConnections\DropServerDatabaseAction;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Exact bindings and DB guards. Swamp owns the execution loop. */
final readonly class DatabaseOperationExecution implements OperationFamilyChildren, OperationFamilyExecution
{
    public function __construct(private DatabaseIntentSource $source) {}

    public function context(Operation $operation): array
    {
        $descriptors = [];
        foreach ($this->intent($operation)->payload['commands'] as $key => $command) {
            $input = $command['input'];
            if (isset($command['export_intent_id'])) {
                $exportKey = 'database.sqlite_export.'.$command['export_intent_id'];
                $export = Operation::query()->where('parent_operation_id', $operation->id)->where('child_key', $exportKey)->where('status', 'succeeded')->exists()
                    ? app(OperationChildren::class)->requireSucceeded($operation, $exportKey) : null;
                if ($export !== null) {
                    $exportResult = ValidatedData::object($export->result ?? []);
                    foreach (['bytes', 'sha256', 'mode'] as $field) {
                        if (array_key_exists($field, $input) && array_key_exists($field, $exportResult)
                            && ! ($field === 'mode' && is_string($input[$field]))) {
                            $input[$field] = $exportResult[$field];
                        }
                    }
                    if (array_key_exists('expected_size', $input)) {
                        $input['expected_size'] = ValidatedData::integer($exportResult['bytes'] ?? null);
                        $input['expected_digest'] = ValidatedData::string($exportResult['sha256'] ?? null);
                    }
                }
            }
            if (isset($command['incoming_step'])) {
                $steps = ValidatedData::object($operation->state['steps'] ?? []);
                $prepared = ValidatedData::object($steps[$command['incoming_step']] ?? []);
                if (in_array($prepared['state'] ?? '', ['resume', 'complete'], true)) {
                    continue;
                }
                $input['target_path'] = $prepared['incoming'] ?? '';
            }
            foreach ($command['protected'] ?? [] as $parameter) {
                Arr::set($input, $parameter, ['$secret' => 'database.intent', 'id' => $operation->input['intent_id']]);
            }
            $descriptors[] = ['key' => $key, 'step' => $key, 'transport' => $command['transport'] ?? 'agent',
                'name' => $command['name'], 'input' => $input, 'resumable' => $command['resumable'] ?? true,
                'required' => true, 'cleanup' => $command['cleanup'] ?? false];
        }

        $eligible = array_column($this->childContext($operation)['children'], 'key');
        $children = array_values(array_filter($this->intent($operation)->payload['projection']['children'] ?? [], static fn (array $child): bool => in_array($child['key'], $eligible, true)));
        $descriptors[] = ['key' => 'database-complete', 'step' => 'database-complete', 'transport' => 'gateway',
            'name' => 'database.proven', 'input' => ['intent_id' => $operation->input['intent_id'], 'children' => $children], 'resumable' => true, 'required' => true];

        return ['descriptors' => $descriptors];
    }

    public function childContext(Operation $parent): array
    {
        $children = $this->intent($parent)->payload['projection']['children'] ?? [];
        $runs = ValidatedData::object($parent->state['executor_runs'] ?? []);
        $finished = true;
        foreach ($this->intent($parent)->payload['commands'] as $key => $command) {
            if (! is_array($runs[$key] ?? null) || ! is_array($runs[$key]['result'] ?? null)) {
                $finished = false;
            }
        }
        $failed = array_any($runs, static fn (mixed $run): bool => is_array($run) && is_array($run['result'] ?? null) && isset($run['result']['error_code']));
        foreach ($children as $child) {
            if (($child['phase'] ?? 'before') === 'before' && $parent->children()->where('child_key', $child['key'])->exists()) {
                $proven = app(OperationChildren::class)->child($parent, $child['key']);
                $failed = $failed || in_array($proven->status->value, ['failed', 'cancelled'], true);
            }
        }
        $eligible = [];
        foreach ($children as $child) {
            if (($child['phase'] ?? 'before') === 'after' && ! $finished && ! $failed) {
                continue;
            }
            unset($child['phase']);
            $eligible[] = $child;
        }

        return ['intent' => ['node_ids' => array_values(array_unique(array_column($children, 'node_id')))], 'children' => $eligible];
    }

    public function input(Operation $operation, array $run): array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === $run['key'] && $descriptor['transport'] === $run['transport'] && $descriptor['name'] === $run['name']) {
                return $descriptor['input'];
            }
        }
        throw new ResourceOperationException('operation.execution_forbidden', 'This database command was not admitted.', 422);
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $this->input($operation, $run);
        foreach ($this->intent($operation)->payload['projection']['children'] ?? [] as $child) {
            if (($child['phase'] ?? 'before') === 'before' && ! ($this->intent($operation)->payload['commands'][$run['key']]['cleanup'] ?? false)) {
                app(OperationChildren::class)->requireSucceeded($operation, $child['key']);
            }
        }
        $command = $this->intent($operation)->payload['commands'][$run['key']] ?? [];
        if (isset($command['reset_database'])) {
            app(DatabaseOwnership::class)->assertMysqlNames(DatabaseConnection::query()->findOrFail(ValidatedData::integer($operation->input['connection_id'])), [$command['reset_database']]);
        }
        if ($operation->kind === 'database.destroy') {
            if (($operation->input['driver'] ?? null) === 'mysql') {
                foreach ($this->intent($operation)->payload['projection']['drop_databases'] ?? [] as $id => $names) {
                    app(DatabaseOwnership::class)->assertMysqlNames(DatabaseConnection::query()->findOrFail($id), $names);
                }
            }
            app(DropServerDatabaseAction::class)->assertUsersStillUnshared($operation, $this->intent($operation));
            foreach (ValidatedData::integerList($operation->input['connection_ids'] ?? [$operation->input['connection_id']]) as $id) {
                $connection = DatabaseConnection::query()->findOrFail($id);
                $owner = $operation->input['owner_instance_id'] ?? null;
                if ($connection->targets()->exists() && ($owner === null || $connection->owner_instance_id !== $owner)) {
                    throw new ResourceOperationException('database.connection_attached', 'Detach the connection before removal.', 409);
                }
            }
        }
        if ($operation->kind === 'database_server.destroy' && DatabaseServer::query()->findOrFail($operation->target_id)->databaseConnections()->exists()) {
            throw new ResourceOperationException('database.server_in_use', 'The server still holds databases.', 409);
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if ($run['name'] === 'database.proven') {
            return $receipt;
        }
        if (isset($receipt['error_code'])) {
            return ['error_code' => $receipt['error_code']];
        }
        $data = ValidatedData::object($receipt['data'] ?? []);
        if (isset($data['error_code'])) {
            return ['error_code' => $data['error_code']];
        }
        $command = $this->intent($operation)->payload['commands'][$run['key']];
        $rows = isset($receipt['output']) && is_string($receipt['output'])
            ? array_values(array_filter(preg_split('/\R/', $receipt['output']) ?: [], static fn (string $line): bool => $line !== '')) : [];
        if (($command['expect_empty'] ?? false) && $rows !== []) {
            return ['error_code' => $command['refusal'] ?? 'database.name_conflict'];
        }

        $created = $this->intent($operation)->payload['projection']['database_creates'][$run['key']] ?? null;
        if ($created !== null) {
            $connection = DatabaseConnection::query()->findOrFail(ValidatedData::integer($operation->input['connection_id']));
            $proofs = array_values(array_filter($connection->creation_receipts ?? [], static fn (array $proof): bool => $proof['database'] !== $created));
            $proofs[] = ['database' => $created, 'operation_id' => $operation->id, 'step' => $run['key'], 'username' => $connection->username, 'server_id' => $connection->database_server_id];
            $connection->forceFill(['creation_receipts' => $proofs])->save();
        }

        $result = ['backend' => $receipt['backend'] ?? $command['backend'] ?? 'active', ...array_intersect_key($receipt, array_flip(['row_count', 'truncated', 'bytes', 'sha256', 'mode', 'incoming', 'state'])), ...$data];
        if (isset($command['expect_state']) && ($result['state'] ?? null) !== $command['expect_state']) {
            return ['error_code' => 'sqlite.seed_cleanup_unproven'];
        }
        if ($operation->kind === 'database.sqlite_export' && (! is_int($result['bytes'] ?? null) || $result['bytes'] < 16
            || ! is_string($result['sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $result['sha256']) !== 1)) {
            return ['error_code' => 'database.snapshot_unproven'];
        }

        return $result;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            $result = $outcome->result ?? [];
            if ($result !== [] && $result !== ['error_code' => $outcome->errorCode]
                && ! array_any(ValidatedData::object($operation->state['executor_runs'] ?? []), static fn (mixed $run): bool => is_array($run) && ($run['result'] ?? null) === $result)) {
                throw new ResourceOperationException('operation.completion_unproven', 'The database failure result has no command receipt.', 409);
            }

            return;
        }
        $this->assertEffects($operation);
    }

    /** Proves every planned effect and exact child before the final read-only receipt. */
    public function assertEffects(Operation $operation): void
    {
        foreach ($this->intent($operation)->payload['projection']['children'] ?? [] as $child) {
            app(OperationChildren::class)->requireSucceeded($operation, $child['key']);
        }
        $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
        $steps = ValidatedData::object($operation->state['steps'] ?? []);
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === 'database-complete') {
                continue;
            }
            $recorded = $runs[$descriptor['key']] ?? null;
            $result = $steps[$descriptor['step']] ?? null;
            if (! is_array($recorded) || ! is_array($result) || isset($result['error_code'])
                || ($recorded['result'] ?? null) !== $result || ($recorded['name'] ?? null) !== $descriptor['name']
                || ($recorded['transport'] ?? null) !== $descriptor['transport']
                || ($recorded['step'] ?? null) !== $descriptor['step']
                || ($result['backend'] ?? null) !== ($this->intent($operation)->payload['commands'][$descriptor['key']]['backend'] ?? 'active')
                || ! $operation->steps()->where('name', $descriptor['step'])->where('status', 'succeeded')->exists()) {
                throw new ResourceOperationException('operation.completion_unproven', 'Every required database step needs a successful command receipt.', 409);
            }
        }
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        foreach ($this->intent($operation)->payload['commands'] as $key => $command) {
            if ($command['finish_after_effect'] ?? false) {
                return ['key' => $key, 'transport' => $command['transport'] ?? 'agent', 'name' => $command['name'], 'result' => ['backend' => $command['backend'] ?? 'active']];
            }
        }

        return null;
    }

    private function intent(Operation $operation): DatabaseExecutionIntent
    {
        $id = $this->source->ownerId($operation);
        if ($id === null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The database intent belongs to another target.', 422);
        }

        return DatabaseExecutionIntent::recorded($id);
    }
}
