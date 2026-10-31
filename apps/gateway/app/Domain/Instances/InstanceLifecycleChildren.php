<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * Derives eligible child intents from admitted placement and proven receipts. It never admits or executes children.
 *
 * @phpstan-import-type ChildIntent from OperationFamilyChildren
 */
final readonly class InstanceLifecycleChildren
{
    /** @return array{intent:array<string,mixed>,children:list<ChildIntent>} */
    public function context(Operation $parent): array
    {
        $id = ValidatedData::integer($parent->input['instance_id']);
        if ($parent->target_type !== 'instance' || $parent->target_id !== $id || $parent->node_id === null) {
            throw new ResourceOperationException('operation.child_forbidden', 'Lifecycle children require their recorded Instance and Node.', 422);
        }
        $children = match ($parent->kind) {
            'instance.create' => $this->create($parent, $id),
            'instance.rename' => $this->rename($parent),
            'instance.register' => $this->register($parent),
            'instance.clone' => $this->clone($parent, $id),
            'instance.destroy' => $this->destroy($parent),
            'instance.transfer' => $this->transfer($parent, $id),
            default => [],
        };
        $indexed = [];
        foreach ($children as $intent) {
            $indexed[$intent['key']] = $intent;
        }
        // Earlier intents survive DB projections and row deletion. Their stamped snapshots remain the authority.
        foreach ($parent->children()->get() as $child) {
            app(OperationChildren::class)->verify($parent, $child);
            $intent = ValidatedData::object($child->child_admission['intent'] ?? []);
            $key = ValidatedData::string($intent['key']);
            $indexed[$key] = ['key' => $key, 'kind' => ValidatedData::string($intent['kind']),
                'node_id' => $intent['node_id'] === null ? null : ValidatedData::integer($intent['node_id']),
                'input' => ValidatedData::object($intent['input'])];
        }
        $nodes = [$parent->node_id];
        foreach ($indexed as $intent) {
            if ($intent['node_id'] !== null) {
                $nodes[] = $intent['node_id'];
            }
        }

        return ['intent' => ['node_ids' => array_values(array_unique($nodes))], 'children' => array_values($indexed)];
    }

    /** @return list<ChildIntent> */
    private function create(Operation $parent, int $id): array
    {
        $input = $parent->input;
        if ($input['initial_status'] === 'active' && $input['first_setup_pending'] === false) {
            return [];
        }
        $children = [];
        $new = $input['initial_status'] !== 'active';
        if ($new && $this->ready($parent, 'source-configuration')) {
            $children[] = $this->child($parent, 'environment-sync', 'instance.sync_environment', ['instance_id' => $id]);
            if ($this->succeeded($parent, 'environment-sync')) {
                $children[] = $this->child($parent, 'vp-environment', 'instance.prepare_vp_environment', ['instance_id' => $id]);
            }
            if ($input['route_id'] !== null && $this->succeeded($parent, 'vp-environment')) {
                $children[] = $this->child($parent, 'route-converge', 'route.converge', ['instance_id' => $id, 'route_id' => $input['route_id']]);
            }
        }
        $activated = $this->ready($parent, $new ? 'instance-activate' : 'source-inspect');
        $needsDatabase = $input['database_server_id'] !== null || $input['database_clone_source_id'] !== null;
        if ($activated && $needsDatabase && $this->ready($parent, 'database-intent')) {
            $children[] = app(InstanceDatabaseIntent::class)->child($parent, 'database');
            if ($this->succeeded($parent, 'database')) {
                $children[] = $this->child($parent, 'database-environment-sync', 'instance.sync_environment', ['instance_id' => $id]);
            }
        }
        if ($activated && (! $needsDatabase || $this->succeeded($parent, 'database-environment-sync'))) {
            $children[] = $this->steps($parent, 'setup', $id, 'setup', $input['setup_digest']);
        }
        if ($new && $this->rollbackSetup($parent)) {
            $children[] = $this->steps($parent, 'rollback-teardown', $id, 'teardown', $input['teardown_digest']);
        }
        if ($new && $this->canRemoveFailedCreate($parent)) {
            $children[] = $this->child($parent, 'rollback-removal', InstanceDestroyKind::forRollback($parent), ['instance_id' => $id, 'force' => true]);
        }

        return $children;
    }

    /** @return list<ChildIntent> */
    private function rename(Operation $parent): array
    {
        return $parent->input['domain'] !== null && $this->ready($parent, 'rename-inspect')
            ? [$this->child($parent, 'route-update', 'route.update', ['route_id' => $parent->input['route_id'], 'domain' => $parent->input['domain']])]
            : [];
    }

    /** @return list<ChildIntent> */
    private function register(Operation $parent): array
    {
        $children = [];
        foreach (InstanceLifecycleInput::members($parent->input) as $member) {
            $id = ValidatedData::integer($member['instance_id']);
            if ($member['route_id'] !== null && ($member['completed'] === true || $this->ready($parent, "registration-{$id}-configuration"))) {
                $children[] = $this->child($parent, "route-{$id}", 'route.converge', ['route_id' => $member['route_id'], 'instance_id' => $id]);
            }
        }
        if ($parent->input['run_setup'] === true) {
            $primary = ValidatedData::integer($parent->input['instance_id']);
            $member = array_find(InstanceLifecycleInput::members($parent->input), static fn (array $member): bool => $member['instance_id'] === $primary);
            if ($member !== null && ($member['completed'] === true || $this->ready($parent, "registration-{$primary}-activate"))) {
                $children[] = $this->steps($parent, 'setup', $primary, 'setup', $parent->input['setup_digest']);
            }
        }

        return $children;
    }

    /** @return list<ChildIntent> */
    private function clone(Operation $parent, int $id): array
    {
        if ($parent->input['initial_completed'] === true) {
            return [];
        }
        $children = [];
        if ($this->ready($parent, 'clone-environment')) {
            if ($parent->input['sqlite_source_path'] !== null && $this->ready($parent, 'clone-sqlite-intent')
                && ! app(InstanceSqliteSeedIntent::class)->retainedClone($parent)) {
                $children[] = app(InstanceSqliteSeedIntent::class)->cloneChild($parent);
            }
            if ($parent->input['sqlite_source_path'] === null || $this->ready($parent, 'clone-sqlite-attach')) {
                $children[] = $this->child($parent, 'clone-environment-sync', 'instance.sync_environment', ['instance_id' => $id]);
            }
        }
        if ($this->ready($parent, 'clone-definitions') && $this->succeeded($parent, 'clone-environment-sync')) {
            $children[] = $this->child($parent, 'clone-processes', 'process.install_instance_definitions', ['instance_id' => $id]);
            $children[] = $this->child($parent, 'clone-schedules', 'schedule.install_instance_definitions', ['instance_id' => $id]);
            if ($parent->input['route_id'] !== null && $this->succeeded($parent, 'clone-processes') && $this->succeeded($parent, 'clone-schedules')) {
                $children[] = $this->child($parent, 'route-converge', 'route.converge', ['instance_id' => $id, 'route_id' => $parent->input['route_id']]);
            }
        }

        return $children;
    }

    /** @return list<ChildIntent> */
    private function destroy(Operation $parent): array
    {
        $input = app(RemovalLifecycleExecution::class)->intent($parent);
        if (! isset($input['members'])) {
            return [];
        }
        $children = [];
        foreach (InstanceLifecycleInput::members($input) as $member) {
            $id = ValidatedData::integer($member['instance_id']);
            if (($member['row_deleted_at'] ?? null) !== null) {
                continue;
            }
            if ($member['run_teardown'] === true && $this->ready($parent, 'removal-inspect')) {
                $children[] = $this->steps($parent, "teardown-{$id}", $id, 'teardown', $member['teardown_digest']);
            }
            if ($this->ready($parent, "removal-{$id}-source-prepare")) {
                if ($member['route_id'] !== null && $member['environment'] !== 'production' && ($member['route_cleared_at'] ?? null) === null) {
                    $children[] = $this->child($parent, "withdraw-{$id}", 'route.withdraw', ['route_id' => $member['route_id']], ValidatedData::integer($member['route_node_id']));
                }
                if ($member['environment'] !== 'production') {
                    $children[] = $this->child($parent, "withdraw-instance-{$id}", 'route.withdraw_instance', ['instance_id' => $id]);
                }
            }
            if ($this->ready($parent, "removal-{$id}-database-intent")
                && app(InstanceRemovalRuntime::class)->succeeded($parent, $id)
                && $this->succeeded($parent, "cleanup-instance-{$id}")) {
                $children[] = app(InstanceDatabaseIntent::class)->child($parent, "remove-databases-{$id}");
            }
            if ($this->ready($parent, "removal-{$id}-runtime-intent")) {
                $children = [...$children, ...app(InstanceRemovalRuntime::class)->children($parent, $id)];
                if (app(InstanceRemovalRuntime::class)->succeeded($parent, $id)) {
                    $children[] = $this->child($parent, "cleanup-instance-{$id}", 'route.cleanup_instance', ['instance_id' => $id]);
                }
            }
        }
        if ($this->ready($parent, 'removal-revalidate')) {
            foreach (ValidatedData::integerList($input['additional_route_ids']) as $route) {
                $projection = array_find(InstanceLifecycleInput::members(['members' => $input['additional_routes']]), static fn (array $entry): bool => $entry['route_id'] === $route);
                $children[] = $this->child($parent, "withdraw-extra-{$route}", 'route.withdraw', ['route_id' => $route], ValidatedData::integer($projection['node_id'] ?? null));
            }
        }

        return [...$children, ...app(ProductionRemovalIntent::class)->children($parent)];
    }

    /** @return list<ChildIntent> */
    private function transfer(Operation $parent, int $id): array
    {
        $transfer = app(InstanceTransferState::class)->transfer($parent);
        if ($parent->input['initial_completed'] === true) {
            return [];
        }
        $children = [];
        $destination = $transfer->destination_node_id;
        $data = ['instance_id' => $id, 'transfer_id' => $transfer->id];
        if (app(TransferCompensation::class)->eligible($parent)) {
            return [$this->child($parent, 'transfer-rollback', 'instance.transfer_rollback', $data)];
        }
        if ($parent->cancel_requested_at !== null && $transfer->cutover_at === null) {
            return [];
        }
        if ($transfer->cutover_at === null) {
            $children[] = $this->child($parent, 'transfer-stop-source', 'process.instance_runtime', ['instance_id' => $id, 'action' => 'transfer_pause']);
            if ($this->ready($parent, 'transfer-capture', 'transfer-sqlite-intent')) {
                $children[] = $this->child($parent, 'transfer-destination', 'instance.transfer_destination', $data, $destination);
            }
        } else {
            $children[] = $this->child($parent, 'transfer-activate-destination', 'instance.transfer_activate', $data, $destination);
            if ($this->ready($parent, 'transfer-destination-activated')) {
                $children[] = $this->child($parent, 'transfer-retire-source', 'route.retire_source', ['transfer_id' => $transfer->id]);
                if ($this->succeeded($parent, 'transfer-retire-source')) {
                    $children[] = $this->child($parent, 'transfer-retire-runtime', 'process.instance_runtime', ['instance_id' => $id, 'action' => 'transfer_retire_source']);
                }
            }
        }

        return $children;
    }

    public function succeeded(Operation $parent, string $key): bool
    {
        if (! $parent->children()->where('child_key', $key)->exists()) {
            return false;
        }
        $child = app(OperationChildren::class)->child($parent, $key);

        return $child->status === OperationStatus::Succeeded;
    }

    public function ready(Operation $parent, string ...$keys): bool
    {
        foreach ($keys as $key) {
            try {
                app(InstanceLifecycleExecution::class)->proven($parent, $key);
            } catch (ResourceOperationException $exception) {
                if ($exception->errorCode !== 'operation.completion_unproven') {
                    throw $exception;
                }

                return false;
            }
        }

        return true;
    }

    /** @return array<string,mixed>|null */
    public function stepOutcome(Operation $parent, string $key): ?array
    {
        if (! $parent->children()->where('child_key', $key)->exists()) {
            return null;
        }
        $child = app(OperationChildren::class)->child($parent, $key);
        if (! $child->status->terminal()) {
            return null;
        }

        return app(InstanceLifecycleStepOutcome::class)->read($child);
    }

    public function rollbackSetup(Operation $parent): bool
    {
        return in_array($this->stepOutcome($parent, 'setup')['outcome'] ?? null, ['missing', 'failed', 'deadline'], true);
    }

    public function canRemoveFailedCreate(Operation $parent): bool
    {
        if ($parent->kind !== 'instance.create' || $parent->input['initial_status'] === 'active'
            || $parent->children()->whereNotIn('status', ['succeeded', 'failed', 'cancelled'])->exists()) {
            return false;
        }
        foreach ($parent->children()->get() as $child) {
            app(OperationChildren::class)->verify($parent, $child);
            if ($child->kind === 'instance.run_steps' && ! in_array(app(InstanceLifecycleStepOutcome::class)->read($child)['outcome'],
                ['succeeded', 'missing', 'failed', 'deadline'], true)) {
                return false;
            }
        }
        foreach (['source-prepare', 'source-inspect', 'source-resolve', 'source-profile', 'source-configuration'] as $key) {
            $result = app(InstanceLifecycleExecution::class)->recordedResult($parent, $key);
            if ($this->confirmedRefusal($result)) {
                return true;
            }
        }
        foreach (['environment-sync' => ['instance.sync_environment'], 'vp-environment' => ['instance.prepare_vp_environment'],
            'route-converge' => ['route.converge'], 'database' => ['database.create', 'database.clone', 'database.mysql_clone'],
            'database-environment-sync' => ['instance.sync_environment']] as $key => $kinds) {
            if (! $parent->children()->where('child_key', $key)->exists()) {
                continue;
            }
            $child = app(OperationChildren::class)->child($parent, $key);
            if (! in_array($child->kind, $kinds, true)) {
                throw new ResourceOperationException('operation.child_unproven', 'The refused child has another lifecycle kind.', 409);
            }
            if ($child->status === OperationStatus::Failed && $this->confirmedRefusal(['error_code' => $child->error_code])) {
                return true;
            }
        }
        $teardown = $this->stepOutcome($parent, 'rollback-teardown');

        return $this->rollbackSetup($parent) && in_array($teardown['outcome'] ?? null, ['succeeded', 'missing', 'failed', 'deadline'], true);
    }

    /** @param array<string,mixed> $result */
    public function confirmedRefusal(array $result): bool
    {
        $code = $result['error_code'] ?? null;
        if (isset($result['outcome']) && ! in_array($result['outcome'], ['failed', 'missing'], true)) {
            return false;
        }

        return in_array($code, ['instance.source_step_failed', 'instance.git_failed', 'instance.clone_failed', 'instance.branch_resolution_failed',
            'instance.source_identity_invalid', 'instance.branch_not_checked_out', 'app-dev.php_version_unsupported',
            'instance.database_create_failed', 'instance.database_clone_failed', 'instance.environment_sync_failed'], true);
    }

    /** @param array<string,mixed> $input
     * @return ChildIntent
     */
    private function child(Operation $parent, string $key, string $kind, array $input, ?int $node = null): array
    {
        return ['key' => $key, 'kind' => $kind, 'node_id' => $node ?? $parent->node_id, 'input' => $input];
    }

    /** @return ChildIntent */
    private function steps(Operation $parent, string $key, int $id, string $phase, mixed $digest): array
    {
        return $this->child($parent, $key, 'instance.run_steps', ['instance_id' => $id, 'source' => 'project',
            'list' => $phase, 'configuration_digest' => ValidatedData::string($digest)]);
    }
}
