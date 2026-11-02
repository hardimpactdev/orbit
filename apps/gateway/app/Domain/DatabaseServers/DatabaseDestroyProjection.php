<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseDestroyProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $input = $operation->input;
        app(DatabaseDestroySafety::class)->assertNames(ValidatedData::stringList($input['databases'] ?? []), ValidatedData::stringList($input['test_prefixes'] ?? []));
        if (app(DatabaseIntentSource::class)->ownerId($operation) === null) {
            throw new ResourceOperationException('database.destroy_intent_invalid', 'The removal belongs to another resource or Node.', 422);
        }
        $server = DatabaseServer::query()->findOrFail($operation->target_id);

        return DatabaseDestroyRun::render($server, ValidatedData::string($input['container']), $this->statements(ValidatedData::object($input['statements'])),
            (string) ValidatedData::integer($input['intent_id']), ! $operation->status->terminal());
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            [$data, $result] = self::outcome(ValidatedData::string($operation->input['driver']), $data);
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $data['status'] === 'succeeded') {
                foreach (ValidatedData::integerList($operation->input['connection_ids'] ?? []) as $id) {
                    $connection = DatabaseConnection::query()->find($id);
                    if ($connection === null) {
                        continue;
                    }
                    DatabaseConnectionTarget::query()->where('database_connection_id', $id)->delete();
                    $connection->delete();
                    app(RecordEventBroadcaster::class)->broadcast(RecordEventType::DatabaseDeleted, $id, ['id' => $id, 'slug' => $connection->slug]);
                }
            }

            return $projected;
        });
    }

    /**
     * The native result decides the code: a blocker (the drop batch's `database.in_use` marker), a failed batch, or a
     * planned name still present. Shared with `instance:destroy`, whose server children are this workflow.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function outcome(string $driver, array $data): array
    {
        $native = ValidatedData::object($data['result'] ?? []);
        $steps = ValidatedData::object($native['steps'] ?? []);
        $read = static function (string $step) use ($steps): array {
            $entry = ValidatedData::object($steps[$step] ?? []);

            return [is_int($entry['exit_code'] ?? null) ? $entry['exit_code'] : null,
                array_values(array_filter(array_map(trim(...), explode("\n", ValidatedData::nullableString($entry['stdout'] ?? null) ?? ''))))];
        };
        [$dropExit, $dropLines] = $read('Drop databases and users');
        [, $goneLines] = $read('Check they are gone');
        $failed = ValidatedData::stringList(ValidatedData::object($native['summary'] ?? [])['failed_steps'] ?? []);
        [$step, $code] = match (true) {
            in_array('database.in_use', $dropLines, true) => ['Drop databases and users', 'database.in_use'],
            in_array('Fence new clients', $failed, true) => ['Fence new clients', 'database.server_admin_failed'],
            $dropExit !== null && $dropExit !== 0 => ['Drop databases and users', 'database.server_admin_failed'],
            in_array('Check they are gone', $failed, true) => ['Check they are gone', 'database.destroy_incomplete'],
            // valkey-cli answers a missing ACL user with an empty reply, so any output is a user still present.
            $driver === 'redis' && $goneLines !== [] => ['Check they are gone', 'database.destroy_incomplete'],
            ($data['status'] ?? null) !== 'succeeded' => [$failed[0] ?? null, ValidatedData::nullableString($data['error_code'] ?? null) ?? 'database.destroy_outcome_unknown'],
            default => [null, null],
        };
        if ($code === null) {
            return [$data, ['backend' => 'absent']];
        }

        return [[...$data, 'status' => 'failed', 'error_code' => $code, 'failure' => ['failed_step' => $step, 'stderr_recorded' => true]], ['error_code' => $code]];
    }

    /**
     * @param  array<string, mixed>  $statements
     * @return array{fence: string, drop: string, gone: string, restore: string}
     */
    private function statements(array $statements): array
    {
        return ['fence' => ValidatedData::string($statements['fence'] ?? null), 'drop' => ValidatedData::string($statements['drop'] ?? null),
            'gone' => ValidatedData::string($statements['gone'] ?? null), 'restore' => ValidatedData::string($statements['restore'] ?? null)];
    }
}
