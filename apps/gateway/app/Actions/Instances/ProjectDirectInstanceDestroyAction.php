<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\DatabaseConnections\PrepareOwnedDatabaseDestructionAction;
use App\Actions\DatabaseConnections\ProjectOwnedDatabaseDestructionAction;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceTransfer;
use App\Models\Process;
use App\Models\Route;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Records only. The owning projection supplies immutable intent and a successful bound native result.
 *
 * @phpstan-import-type Artifact from PrepareOwnedDatabaseDestructionAction
 */
final readonly class ProjectDirectInstanceDestroyAction
{
    public function __construct(private ProjectOwnedDatabaseDestructionAction $databases, private RecordEventBroadcaster $broadcaster) {}

    /** @param array<string,mixed> $input
     * @param  array<string,mixed>  $result
     */
    public function execute(array $input, array $result): InstanceRemoval
    {
        $detail = ValidatedData::object($input['direct_destroy']);
        $local = $this->rows($detail['members']);
        $ids = ValidatedData::integerList(array_column($local, 'instance_id'));
        sort($ids);
        $artifacts = array_map($this->artifact(...), $this->rows($detail['databases']));
        $owners = array_column($artifacts, 'owner_instance_id');
        sort($owners);
        $databaseResults = array_map(static fn (array $artifact): array => [
            'artifact_id' => $artifact['artifact_id'], 'owner_instance_id' => $artifact['owner_instance_id'],
            'node_id' => $artifact['node_id'], 'removed_connection_ids' => array_column($artifact['connections'], 'connection_id'), 'backend' => 'absent',
        ], $artifacts);
        // The native final step validates every actual result, then hashes this fixed ordered JSON.
        // The digest bounds the parent wire without dropping set members or regenerating artifacts.
        $expected = ['removal_id' => $input['removal_id'], 'removed' => true, 'instance_ids' => $ids,
            'database_results_sha256' => hash('sha256', json_encode($databaseResults, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
        if (($input['environment'] ?? null) !== 'development' || $ids === [] || count(array_unique($ids)) !== count($ids) || $owners !== $ids
            || count($result) !== count($expected) || array_diff_key($result, $expected) !== []
            || array_any(array_keys($expected), static fn (string $key): bool => ($result[$key] ?? null) !== $expected[$key])) {
            throw new ResourceOperationException('operation.result_invalid', 'Removal requires its complete bounded native result.', 422);
        }

        return DB::transaction(function () use ($input, $detail, $local, $ids, $artifacts, $databaseResults): InstanceRemoval {
            $removal = InstanceRemoval::query()->lockForUpdate()->findOrFail(ValidatedData::string($input['removal_id']));
            $members = $removal->members()->lockForUpdate()->get();
            if ($removal->requested_instance_id !== $input['instance_id'] || $removal->inventory_digest !== $input['inventory_digest']
                || $members->pluck('instance_id')->sort()->values()->all() !== $ids) {
                $this->conflict();
            }
            if ($removal->status === InstanceRemovalStatus::Completed) {
                return $removal->load('members');
            }
            $instances = Instance::query()->with('node.roles')->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($instances->modelKeys() !== $ids) {
                $this->conflict();
            }
            foreach ($instances as $instance) {
                $member = $members->firstWhere('instance_id', $instance->id);
                $savedMember = array_find($local, static fn (array $row): bool => $row['instance_id'] === $instance->id);
                if ($savedMember === null) {
                    $this->conflict();
                }
                $source = ValidatedData::object($savedMember['source']);
                if ($member === null || $instance->status !== InstanceState::Removing || ! $instance->placedOnAppDev()
                    || $instance->project_id !== $input['project_id'] || $instance->node_id !== $input['node_id']
                    || $instance->checkout_path !== $source['checkout'] || $instance->source_layout !== $source['layout']
                    || $member->source_digest !== $source['digest'] || $member->row_deleted_at !== null
                    || $source['operation'] !== $removal->id || $source['member'] !== (string) $member->id
                    || $source['common_repository'] !== $member->common_repository_path || $source['source_identity'] !== $member->source_identity
                    || $savedMember['receipt'] !== hash('sha256', $removal->id."\0".$member->id."\0".$member->source_digest."\0finalized")) {
                    $this->conflict();
                }
                $routes = Route::query()->with('targets')->whereHas('targets', fn ($query) => $query->where('instance_id', $instance->id))->orderBy('id')->get();
                if ($routes->modelKeys() !== $member->route_ids || $routes->contains(static fn (Route $route): bool => $route->project_id !== $instance->project_id || $route->status !== RouteStatus::Retiring || $route->sites_published
                    || $route->targets->count() !== 1 || $route->targets->sole()->instance_id !== $instance->id)) {
                    $this->conflict();
                }
            }
            $runtime = ValidatedData::object($detail['runtime']);
            $processes = Process::query()->whereIn('owner_type', Instance::morphTypes())->whereIn('owner_id', $ids)->orderBy('id')->get();
            $schedules = Schedule::query()->whereIn('target_type', Instance::morphTypes())->whereIn('target_id', $ids)->orderBy('id')->get();
            $expectedProcesses = $this->rows($runtime['processes']);
            $processIds = array_column($expectedProcesses, 'process_id');
            $scheduleIds = array_column($this->rows($runtime['schedules']), 'id');
            sort($processIds);
            sort($scheduleIds);
            if ($processes->modelKeys() !== $processIds || $schedules->modelKeys() !== $scheduleIds) {
                $this->conflict();
            }
            foreach ($processes as $process) {
                $saved = array_find($expectedProcesses, static fn (array $row): bool => $row['process_id'] === $process->id);
                $name = $process->runtime === ProcessRuntime::Systemd
                    ? app(SystemdProcessRenderer::class)->unitName($process) : app(DockerProcessRenderer::class)->containerName($process);
                if ($saved === null || $saved['runtime'] !== $process->runtime->value || $saved['name'] !== $name) {
                    $this->conflict();
                }
            }
            if ($schedules->contains(static fn (Schedule $schedule): bool => $schedule->host_node_id !== $input['node_id'])) {
                $this->conflict();
            }
            // All projections share this transaction; a later conflict rolls back earlier record deletions.
            foreach ($artifacts as $index => $artifact) {
                $target = $instances->firstWhere('id', $artifact['owner_instance_id']);
                if ($target === null) {
                    $this->conflict();
                }
                $this->databases->execute($target, $artifact, $databaseResults[$index]);
            }
            if (DatabaseConnection::query()->whereIn('owner_instance_id', $ids)->exists()) {
                $this->conflict();
            }
            Schedule::query()->whereKey($scheduleIds)->delete();
            Process::query()->whereKey($processIds)->delete();
            $removal->update(['status' => InstanceRemovalStatus::Removing, 'failed_step' => null, 'error_code' => null]);
            foreach ($members as $member) {
                $member->update(['source_prepared_at' => $member->source_prepared_at ?? now()]);
                foreach ($member->route_ids ?? [] as $routeId) {
                    Route::query()->whereKey($routeId)->delete();
                    $this->deleted(RecordEventType::RouteDeleted, $routeId);
                }
                $member->update(['route_cleared_at' => $member->route_cleared_at ?? now(), 'route_outcome' => $member->route_id === null ? 'none' : 'deleted']);
                $source = ValidatedData::object(array_find($local, static fn (array $row): bool => $row['instance_id'] === $member->instance_id));
                $member->update(['source_finalized_at' => $member->source_finalized_at ?? now(), 'finalization_receipt' => $source['receipt'],
                    'runtime_cleaned_at' => $member->runtime_cleaned_at ?? now()]);
                InstanceTransfer::query()->where('instance_id', $member->instance_id)->closed()->update(['instance_id' => null]);
                Instance::query()->whereKey($member->instance_id)->delete();
                $member->update(['row_deleted_at' => now()]);
                $this->deleted(RecordEventType::InstanceDeleted, $member->instance_id);
            }
            foreach ($processes as $process) {
                $this->deleted(RecordEventType::ProcessDeleted, $process->id);
            }
            foreach ($schedules as $schedule) {
                $this->deleted(RecordEventType::ScheduleDeleted, $schedule->id);
            }
            $removal->update(['status' => InstanceRemovalStatus::Completed, 'current_step' => null, 'failed_step' => null, 'error_code' => null]);

            return $removal->refresh()->load('members');
        });
    }

    /** Decode the saved owning artifact without re-reading or regenerating it.
     * @param  array<string,mixed>  $saved
     * @return Artifact
     */
    private function artifact(array $saved): array
    {
        if (($saved['version'] ?? null) !== 1) {
            $this->conflict();
        }

        return ['version' => 1, 'artifact_id' => ValidatedData::string($saved['artifact_id']),
            'owner_instance_id' => ValidatedData::integer($saved['owner_instance_id']),
            'node_id' => ValidatedData::integer($saved['node_id']), 'checkout' => ValidatedData::string($saved['checkout']),
            'agent_sha256' => ValidatedData::string($saved['agent_sha256']),
            'connections' => array_map(static fn (array $row): array => [
                'connection_id' => ValidatedData::integer($row['connection_id']), 'slug' => ValidatedData::string($row['slug']),
                'driver' => ValidatedData::string($row['driver']),
                'node_id' => $row['node_id'] === null ? null : ValidatedData::integer($row['node_id']),
                'path' => ValidatedData::nullableString($row['path']),
            ], $this->rows($saved['connections']))];
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->conflict();
        }

        return array_map(ValidatedData::object(...), $value);
    }

    private function deleted(RecordEventType $type, int|string $id): void
    {
        DB::afterCommit(fn () => $this->broadcaster->broadcast($type, $id, ['id' => $id]));
    }

    private function conflict(): never
    {
        throw new ResourceOperationException('instance.removal_conflict', 'The admitted removal inventory or resource ownership changed.', 409);
    }
}
