<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Data\Instances\InstanceData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseStatements;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeFailedStep;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** The `operation-instance-restore` input, and the one transaction that records a restored Instance. */
final readonly class InstanceRestoreProjection implements DirectOperationProjection
{
    public const string ExecutionCopy = '^instance-restore-[1-9][0-9]*-[1-9][0-9]*-(check|clear)$';

    public function __construct(private DirectOperation $direct, private RecordEventBroadcaster $broadcaster) {}

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        $push = ! $operation->status->terminal();
        $servers = [];
        $copies = [];
        foreach (RouteExecutionProof::objects($input['servers'] ?? []) as $row) {
            $server = DatabaseServer::query()->findOrFail(ValidatedData::integer($row['server_id']));
            $statements = app(DatabaseStatements::class)->restore(ValidatedData::stringList($row['databases']));
            foreach (['check', 'clear'] as $batch) {
                $key = "instance-restore-{$operation->target_id}-{$server->id}-{$batch}";
                // The admin password is the first stdin line; it goes Gateway → vault → stdin, never into the intent.
                $copies["server_{$server->id}_{$batch}"] = $push
                    ? app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', $key, $server->root_password."\n".$statements[$batch])
                    : ['vault' => 'orbit-control-inputs', 'key' => $key];
            }
            $servers[] = ['server_id' => $server->id, 'node_id' => $server->node_id,
                'admin_client' => DatabaseStatements::login(DatabaseDriver::Pgsql, ValidatedData::string($row['container']), 'postgres', 'postgres'),
                'ssh_execution' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail($server->node_id))];
        }

        return ['intent' => ['instance_id' => $input['instance_id'], 'servers' => $servers, 'route_create' => $input['route_create'],
            'route_secrets' => $input['route_secrets']], 'execution_secrets' => $copies];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->refresh();
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $terminal = $operation->status->terminal();
            $status = ValidatedData::string($data['status']);
            $native = ValidatedData::object($data['result'] ?? []);
            [$code, $result] = $this->outcome($operation, $data, $native);
            $projected = $this->direct->project($operation, [...$data, 'error_code' => $code], $result);
            if ($terminal || $status !== 'succeeded') {
                return $projected;
            }
            $this->restore($operation);

            return $projected;
        });
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $native
     * @return array{0:?string,1:array<string,mixed>}
     */
    private function outcome(Operation $operation, array $data, array $native): array
    {
        $ids = ['instance_id' => $operation->input['instance_id'], 'removal_id' => $operation->input['removal_id'], 'route_id' => $operation->input['route_id']];
        if ($data['status'] === 'succeeded') {
            if (NativeRunResult::stepStatus($native, 'Republish the Route') !== 'succeeded') {
                throw new ResourceOperationException('operation.result_invalid', 'Restore has no successful result for Republish the Route.', 422);
            }

            return [null, ['restored' => true, ...$ids]];
        }
        if (! in_array($data['status'], ['failed', 'interrupted'], true)) {
            return [ValidatedData::nullableString($data['error_code'] ?? null), []];
        }
        $step = NativeFailedStep::label($data, ['Check the databases', 'Republish the Route', 'Clear the fence journal']);
        $detail = is_array($data['failure'] ?? null) && is_string($data['failure']['detail'] ?? null) ? $data['failure']['detail'] : '';
        $code = match ($step) {
            'Check the databases', 'Clear the fence journal' => preg_match('/instance\.restore_database_(closed|missing)/', $detail, $match) === 1
                ? 'instance.restore_database_'.$match[1] : 'instance.restore_database_check_failed',
            'Republish the Route' => 'instance.restore_publication_failed',
            default => ValidatedData::nullableString($data['error_code'] ?? null) ?? 'instance.restore_failed',
        };

        return [$code, ['restored' => false, 'failed_step' => $step, 'error_code' => $code, ...$ids]];
    }

    /**
     * Members and removal abandoned, the same Route active, then the Instance active, in that order: the schema refuses an
     * active Laravel Instance without exactly one active Route (`instances_active_route_update`).
     */
    private function restore(Operation $operation): void
    {
        $instance = Instance::query()->lockForUpdate()->findOrFail(ValidatedData::integer($operation->input['instance_id']));
        $removal = InstanceRemoval::query()->lockForUpdate()->findOrFail(ValidatedData::string($operation->input['removal_id']));
        $route = Route::query()->lockForUpdate()->findOrFail(ValidatedData::integer($operation->input['route_id']));
        if ($instance->status !== InstanceState::Removing || $removal->status !== InstanceRemovalStatus::Failed || $route->status !== RouteStatus::Retiring) {
            throw new ResourceOperationException('operation.completion_conflict', 'The failed removal changed while the restore ran.', 409);
        }
        $removal->members()->update(['abandoned_at' => now()]);
        $removal->update(['status' => InstanceRemovalStatus::Abandoned, 'current_step' => null, 'failed_step' => null, 'error_code' => null]);
        $route->update(['status' => RouteStatus::Active, 'sites_published' => true, 'failed_step' => null, 'error_code' => null]);
        $instance->update(['status' => InstanceState::Active]);
        $this->broadcaster->broadcast(RecordEventType::InstanceUpdated, $instance->id,
            InstanceData::fromModel($instance->load(['project', 'node', 'routes.targets']))->toArray());
    }
}
