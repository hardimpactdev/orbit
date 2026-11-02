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

/** One read over official SSH: the Gateway builds the client argv and its stdin, and shapes the client output into rows. */
final readonly class DatabaseReadProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $intent = $this->intent($operation);
        $command = $intent->payload['commands']['database-apply']['input'];
        $driver = DatabaseDriver::from(ValidatedData::string($operation->input['driver']));
        $statements = DatabaseRead::statements($driver, ValidatedData::string($operation->input['mode']),
            ValidatedData::string($command['sql']), ValidatedData::nullableString($operation->input['table'] ?? null) ?? '');
        $secret = ['vault' => 'orbit-control-inputs', 'key' => 'database-read-intent-'.$intent->id];
        if (! $operation->status->terminal()) {
            // MySQL and PostgreSQL read the password from the first stdin line; the statements follow (Q-DBAUTH).
            $secret = app(SwampCommandStep::class)->executionCopy($secret['vault'], $secret['key'],
                ($driver === DatabaseDriver::Sqlite ? '' : ValidatedData::string($command['password'])."\n").$statements);
        }

        $target = ValidatedData::object($command['target']);
        $null = ValidatedData::string($command['null']);
        // A registered external database is read on 209 itself; every other read runs over official SSH.
        $external = array_key_exists('host', $target);
        $text = static fn (string $key): string => ! $external ? '' : (is_int($target[$key] ?? null) ? (string) $target[$key] : ValidatedData::string($target[$key] ?? ''));

        return ['intent' => ['argv' => $external ? [] : DatabaseRead::argv($driver, $target, $null),
            'local' => ['client' => $external ? DatabaseRead::client($driver) : '', 'user' => $text('user'), 'database' => $text('database'),
                'host' => $text('host'), 'port' => $text('port'), 'marker' => $external ? $null : ''],
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($operation->node_id))],
            'execution_secrets' => ['read' => $secret]];
    }

    /** The native result decides: a failed client is database.query_failed; its output becomes the rows. */
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
                $result = DatabaseRead::result(DatabaseDriver::from(ValidatedData::string($operation->input['driver'])), ValidatedData::string($operation->input['mode']),
                    ValidatedData::nullableString($operation->input['table'] ?? null) ?? '', $output,
                    ValidatedData::string($command['null']), ValidatedData::string($command['password']));
            } catch (ResourceOperationException) {
                $result = ['error_code' => 'database.query_failed'];
            }
            if (isset($result['error_code'])) {
                $data = [...$data, 'status' => 'failed', 'error_code' => $result['error_code'], 'failure' => ['failed_step' => 'Run the read', 'stderr_recorded' => true]];
            }
        }

        return app(DirectOperation::class)->project($operation, $data, $result);
    }

    private function intent(Operation $operation): DatabaseExecutionIntent
    {
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        if ($operation->kind !== 'database.read' || $id === null) {
            throw new ResourceOperationException('database.read_intent_invalid', 'The read does not match its admitted connection.', 422);
        }

        return DatabaseExecutionIntent::recorded($id);
    }
}
