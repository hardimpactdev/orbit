<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseExecutionIntent;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** One write with the read's client and placement; the result is the affected row count, never rows. */
final readonly class DatabaseWriteProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $intent = $this->intent($operation);
        $command = $intent->payload['commands']['database-apply']['input'];
        $driver = DatabaseDriver::from(ValidatedData::string($operation->input['driver']));
        $statements = DatabaseRead::writeStatements($driver, ValidatedData::string($command['sql']));
        $secret = ['vault' => 'orbit-control-inputs', 'key' => 'database-write-intent-'.$intent->id];
        if (! $operation->status->terminal()) {
            // MySQL and PostgreSQL read the password from the first stdin line; the statement follows (Q-DBAUTH).
            $secret = app(SwampCommandStep::class)->executionCopy($secret['vault'], $secret['key'],
                ($driver === DatabaseDriver::Sqlite ? '' : ValidatedData::string($command['password'])."\n").$statements);
        }
        $target = ValidatedData::object($command['target']);
        $null = ValidatedData::string($command['null']);
        // A registered external database is written on 209 itself; every other write runs over official SSH.
        $external = array_key_exists('host', $target);
        $text = static fn (string $key): string => ! $external ? '' : (is_int($target[$key] ?? null) ? (string) $target[$key] : ValidatedData::string($target[$key] ?? ''));

        return ['intent' => ['argv' => $external ? [] : DatabaseRead::argv($driver, $target, $null, write: true),
            'local' => ['client' => $external ? DatabaseRead::client($driver, write: true) : '', 'user' => $text('user'), 'database' => $text('database'),
                'host' => $text('host'), 'port' => $text('port'), 'marker' => $external ? $null : ''],
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id))],
            'execution_secrets' => ['write' => $secret]];
    }

    /** The native result decides: a failed client is database.query_failed; its last line is the affected row count. */
    public function apply(Operation $operation, array $data): Operation
    {
        $result = ['error_code' => 'database.query_failed'];
        if (($data['status'] ?? null) === 'failed') {
            $data = [...$data, 'error_code' => 'database.query_failed'];
        } elseif (($data['status'] ?? null) === 'succeeded') {
            $command = $this->intent($operation)->payload['commands']['database-apply']['input'];
            $captured = ValidatedData::object($data['result'] ?? []);
            $output = ValidatedData::nullableString($captured['output'] ?? $captured['local_output'] ?? null) ?? '';
            try {
                $result = DatabaseRead::written(DatabaseDriver::from(ValidatedData::string($operation->input['driver'])), $output,
                    ValidatedData::string($command['null']));
            } catch (ResourceOperationException) {
                $data = [...$data, 'status' => 'failed', 'error_code' => 'database.write_outcome_unknown',
                    'failure' => ['failed_step' => 'Run the statement', 'stderr_recorded' => true]];
                $result = ['error_code' => 'database.write_outcome_unknown'];
            }
        }

        return app(DirectOperation::class)->project($operation, $data, $result);
    }

    private function intent(Operation $operation): DatabaseExecutionIntent
    {
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        if ($operation->kind !== 'database.write' || $operation->target_type !== 'database_connection' || $id === null) {
            throw new ResourceOperationException('database.write_intent_invalid', 'The write does not match its admitted Connection and Node.', 422);
        }

        return DatabaseExecutionIntent::recorded($id);
    }
}
