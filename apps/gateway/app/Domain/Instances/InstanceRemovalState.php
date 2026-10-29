<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\InstanceSourceRevalidationExpectation;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Operations\OperationStore;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\InstanceTransfer;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Route;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** State and read-only revalidation for one immutable development removal set. */
final readonly class InstanceRemovalState
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function transition(Operation $operation, array $input): array
    {
        return match ($input['action']) {
            'removal-production-prepare', 'removal-production-finalize', 'removal-route-detach' => app(ProductionRemovalIntent::class)->transition($operation, ValidatedData::string($input['action'])),
            'removal-inspect' => $this->inspect($operation),
            'removal-revalidate' => $this->revalidate($operation),
            'removal-accept' => $this->accept($operation),
            'removal-route-clear' => $this->clearRouteTransition($operation, ValidatedData::integer($input['instance_id'])),
            'removal-database-intent' => app(InstanceDatabaseIntent::class)->remove($operation, ValidatedData::integer($input['instance_id'])),
            'removal-runtime-intent' => $this->runtime($operation, ValidatedData::integer($input['instance_id'])),
            'removal-row-delete' => $this->delete($operation, ValidatedData::integer($input['instance_id'])),
            default => throw new ResourceOperationException('operation.execution_forbidden', 'Unknown removal state transition.', 422),
        };
    }

    /** @return array<string, mixed> */
    private function inspect(Operation $operation): array
    {
        $intent = app(RemovalLifecycleExecution::class)->intent($operation);
        if (! isset($intent['members'])) {
            $instance = Instance::query()->findOrFail(ValidatedData::integer($intent['instance_id']));
            $intent = app(StartInstanceDestroyAction::class)->snapshot($instance, $intent['force'] === true,
                app(InstanceRouteRemovalAdmission::class)->parent($operation));
            app(OperationStore::class)->change($operation, ['state' => [...($operation->state ?? []), 'lifecycle_removal' => $intent]]);
        }

        return ['inspected' => true, 'member_ids' => array_column(InstanceLifecycleInput::members($intent), 'instance_id')];
    }

    /** @return array<string, mixed> */
    private function revalidate(Operation $operation): array
    {
        $intent = app(RemovalLifecycleExecution::class)->intent($operation);
        if (isset($intent['production_home'])) {
            app(ProductionRemovalIntent::class)->validate($operation);
        } elseif (InstanceRemoval::query()->whereKey($intent['removal_id'])->exists()) {
            $this->revalidateRetained($operation);
        } else {
            $instance = Instance::query()->findOrFail(ValidatedData::integer($intent['instance_id']));
            $current = app(StartInstanceDestroyAction::class)->snapshot($instance, $intent['force'] === true,
                app(InstanceRouteRemovalAdmission::class)->parent($operation));
            if ($current['inventory_digest'] !== $intent['inventory_digest']
                || array_column(InstanceLifecycleInput::members($current), 'instance_id') !== array_column(InstanceLifecycleInput::members($intent), 'instance_id')) {
                throw new ResourceOperationException('instance.remove_refused', 'Teardown changed the source ownership. The Instance remains.', 409);
            }
        }

        return ['revalidated' => true];
    }

    /** @return array<string, mixed> */
    private function accept(Operation $operation): array
    {
        $intent = app(RemovalLifecycleExecution::class)->intent($operation);
        $ids = ValidatedData::integerList(array_column(InstanceLifecycleInput::members($intent), 'instance_id'));
        app(ProcessAdmissionLock::class)->run($ids, function () use ($operation, $intent, $ids): void {
            DB::transaction(function () use ($operation, $intent, $ids): void {
                foreach (ValidatedData::integerList($intent['additional_route_ids']) as $routeId) {
                    app(InstanceLifecycleExecution::class)->assertChild($operation, "withdraw-extra-{$routeId}", 'route.withdraw');
                    $route = Route::query()->lockForUpdate()->find($routeId);
                    if ($route !== null && $route->targets()->whereNotIn('instance_id', $ids)->exists()) {
                        throw new ResourceOperationException('instance.removal_conflict', 'The additional Route target set changed.', 409);
                    }
                    $route?->delete();
                }
                $removal = InstanceRemoval::query()->firstOrCreate(['id' => $intent['removal_id']], [
                    'requested_instance_id' => $intent['instance_id'], 'requested_name' => $intent['name'],
                    'force' => $intent['force'], 'inventory_digest' => $intent['inventory_digest'], 'total' => count(InstanceLifecycleInput::members($intent)),
                    'status' => InstanceRemovalStatus::Removing, 'current_step' => InstanceRemovalStep::SourcePreparation,
                ]);
                if ($removal->requested_instance_id !== $intent['instance_id'] || $removal->inventory_digest !== $intent['inventory_digest']) {
                    throw new ResourceOperationException('instance.removal_conflict', 'The removal intent changed.', 409);
                }
                $removal->update(['force' => $intent['force'], 'status' => InstanceRemovalStatus::Removing, 'failed_step' => null, 'error_code' => null]);
                foreach (InstanceLifecycleInput::members($intent) as $member) {
                    $removal->members()->firstOrCreate(['instance_id' => $member['instance_id']], Arr::only($member, [
                        'position', 'project_id', 'node_id', 'route_id', 'name', 'environment', 'source_layout', 'repository_identity',
                        'checkout_path', 'root', 'branch', 'starting_commit', 'source_commit', 'common_repository_path', 'source_identity',
                        'linked_worktree_paths', 'source_digest', 'runtime_published',
                    ]));
                }
                Instance::query()->whereKey($ids)->update(['status' => InstanceState::Removing]);
            });
        });

        return ['accepted' => true, 'removal_id' => $intent['removal_id']];
    }

    /** @return array<string, mixed> */
    private function clearRouteTransition(Operation $operation, int $id): array
    {
        $this->clearRoute($operation, $id);

        return ['route_cleared' => true, 'instance_id' => $id];
    }

    public function clearRoute(Operation $operation, int $instanceId): void
    {
        $intent = app(RemovalLifecycleExecution::class)->intent($operation);
        $member = InstanceRemovalMember::query()->where('instance_removal_id', $intent['removal_id'])->where('instance_id', $instanceId)->firstOrFail();
        if ($member->route_cleared_at !== null) {
            return;
        }
        if ($member->environment === 'production') {
            app(ProductionRemovalIntent::class)->clear($operation, $member);

            return;
        }
        $execution = app(InstanceLifecycleExecution::class);
        $execution->assertChild($operation, "withdraw-instance-{$instanceId}", 'route.withdraw_instance');
        if ($member->route_id !== null) {
            $execution->assertChild($operation, "withdraw-{$instanceId}", 'route.withdraw');
            $route = Route::query()->find($member->route_id);
            if ($route !== null && ($route->targets()->count() !== 1 || $route->targets()->value('instance_id') !== $instanceId)) {
                throw new ResourceOperationException('instance.removal_conflict', 'The Route target changed before removal.', 409);
            }
            $route?->delete();
        }
        $member->update(['route_cleared_at' => now(), 'route_outcome' => $member->route_id === null ? 'none' : 'deleted']);
    }

    /** @return array<string, mixed> */
    private function runtime(Operation $operation, int $id): array
    {
        $intent = app(RemovalLifecycleExecution::class)->intent($operation);
        $member = InstanceRemovalMember::query()->where('instance_removal_id', $intent['removal_id'])->where('instance_id', $id)->firstOrFail();
        if ($member->source_finalized_at === null) {
            throw new ResourceOperationException('operation.completion_unproven', 'Source finalization must finish before runtime cleanup.', 409);
        }

        return [
            'process_ids' => Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $id)->orderBy('id')->pluck('id')->all(),
            'schedule_ids' => Schedule::query()->whereIn('target_type', Instance::morphTypes())->where('target_id', $id)->orderBy('id')->pluck('id')->all(),
            'database_ids' => DatabaseConnection::query()->where('owner_instance_id', $id)->orderBy('id')->pluck('id')->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function delete(Operation $operation, int $id): array
    {
        app(InstanceLifecycleExecution::class)->assertChild($operation, "cleanup-instance-{$id}", 'route.cleanup_instance');
        $intent = app(RemovalLifecycleExecution::class)->intent($operation);
        $member = InstanceRemovalMember::query()->where('instance_removal_id', $intent['removal_id'])->where('instance_id', $id)->firstOrFail();
        if (Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $id)->exists()
            || Schedule::query()->whereIn('target_type', Instance::morphTypes())->where('target_id', $id)->exists()
            || DatabaseConnection::query()->where('owner_instance_id', $id)->exists()) {
            throw new ResourceOperationException('operation.completion_unproven', 'The Instance still owns runtime resources.', 409);
        }
        DB::transaction(static function () use ($member, $id): void {
            $removal = InstanceRemoval::query()->lockForUpdate()->findOrFail($member->instance_removal_id);
            InstanceTransfer::query()->where('instance_id', $id)->closed()->update(['instance_id' => null]);
            Instance::query()->whereKey($id)->delete();
            $member->update(['runtime_cleaned_at' => now(), 'row_deleted_at' => now()]);
            if (! $removal->members()->whereNull('row_deleted_at')->exists()) {
                $removal->update(['status' => InstanceRemovalStatus::Completed, 'current_step' => null, 'failed_step' => null, 'error_code' => null]);
            }
        });

        return ['deleted' => true, 'instance_id' => $id];
    }

    public function revalidateRetained(Operation $operation): void
    {
        $intent = app(RemovalLifecycleExecution::class)->intent($operation);
        $members = InstanceRemovalMember::query()->where('instance_removal_id', $intent['removal_id'])->orderBy('position')->get();
        $reader = app(DevelopmentInstanceSourceFinalizer::class);
        $states = [];
        $publicStates = [];
        foreach ($members as $member) {
            $required = $member->linked_worktree_paths;
            $permitted = $required;
            foreach ($members as $peer) {
                if ($peer->common_repository_path !== $member->common_repository_path) {
                    continue;
                }
                if ($peer->source_finalized_at !== null || ($states[$peer->id] ?? null) === InstanceSourceRevalidationState::Completed) {
                    $required = array_values(array_diff($required, [$peer->checkout_path]));
                    $permitted = array_values(array_diff($permitted, [$peer->checkout_path]));
                } elseif (($states[$peer->id] ?? null) === InstanceSourceRevalidationState::ReceiptPendingCleanup) {
                    $required = array_values(array_diff($required, [$peer->checkout_path]));
                }
            }
            $states[$member->id] = $reader->revalidate($member, new InstanceSourceRevalidationExpectation($required, $permitted, $states));
            $publicStates['instance-'.$member->instance_id] = $states[$member->id]->value;
            if ($states[$member->id] === InstanceSourceRevalidationState::Completed && $member->source_finalized_at === null) {
                $receipt = hash('sha256', "{$member->instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized");
                $member->update(['source_finalized_at' => now(), 'finalization_receipt' => $receipt]);
            }
        }
        app(OperationStore::class)->change($operation, ['state' => [...($operation->state ?? []), 'lifecycle_removal_source_states' => $publicStates]]);
    }
}
