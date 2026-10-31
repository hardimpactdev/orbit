<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Operations\OperationFamilyExecution;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceRemoval;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * Source-removal descriptors retain the accepted set, journals and Node ownership proofs.
 *
 * @phpstan-import-type Descriptor from OperationFamilyExecution
 * @phpstan-import-type RunInput from \App\Domain\Operations\SwampOperationExecutor
 */
final readonly class RemovalLifecycleExecution
{
    /** @return list<Descriptor> */
    public function descriptors(Operation $operation): array
    {
        $execution = app(InstanceLifecycleExecution::class);
        $input = $this->intent($operation);
        $descriptors = [];
        foreach (['removal-inspect' => 'removal-inspect', 'removal-revalidate' => 'removal-revalidate', 'removal-accept' => 'removal-accept'] as $key => $action) {
            $descriptors[] = $execution->descriptor($key, 'gateway', 'instance.lifecycle-state', ['action' => $action, 'operation_id' => $operation->id]);
        }
        foreach (InstanceLifecycleInput::members($input) as $member) {
            $id = ValidatedData::integer($member['instance_id']);
            if (($member['row_deleted_at'] ?? null) !== null) {
                continue;
            }
            if ($member['environment'] === 'production') {
                $descriptors = [...$descriptors, ...app(ProductionRemovalIntent::class)->descriptors($operation, $id)];
            } else {
                $record = InstanceRemovalMember::query()->where('instance_removal_id', $input['removal_id'])->where('instance_id', $id)->first();
                $memberId = $record === null ? '' : (string) $record->id;
                $base = [
                    'root' => $member['source_root'], 'operation' => $input['removal_id'], 'member' => $memberId,
                    'digest' => $member['source_digest'], 'user' => $member['user'], 'group' => $member['group'],
                    'checkout' => $member['checkout_path'], 'common_repository' => $member['common_repository_path'],
                    'layout' => $member['source_layout'], 'source_identity' => $member['source_identity'],
                ];
                $descriptors[] = ($member['source_prepared_at'] ?? null) !== null
                    ? $execution->descriptor("removal-{$id}-source-prepare", 'gateway', 'instance.lifecycle-state', ['action' => 'configuration', 'operation_id' => $operation->id])
                    : $execution->descriptor("removal-{$id}-source-prepare", 'instance_lifecycle', 'instance.prepare-source-removal', $base);
                $removalId = ValidatedData::string($input['removal_id']);
                $digest = ValidatedData::string($member['source_digest']);
                $receipt = hash('sha256', "{$removalId}\0{$memberId}\0{$digest}\0finalized");
                $instance = Instance::query()->with('project')->find($id);
                $sourceState = ValidatedData::object($operation->state['lifecycle_removal_source_states'] ?? [])['instance-'.$id] ?? null;
                $descriptors[] = ($member['source_finalized_at'] ?? null) !== null || $record?->source_finalized_at !== null
                    ? $execution->descriptor("removal-{$id}-source-finalize", 'gateway', 'instance.lifecycle-state', ['action' => 'configuration', 'operation_id' => $operation->id])
                    : ($sourceState === InstanceSourceRevalidationState::ReceiptPendingCleanup->value
                        ? $execution->descriptor("removal-{$id}-source-finalize", 'instance_lifecycle', 'instance.clean-removal-receipt', [
                            ...$base, 'receipt' => $receipt, 'grouping_directory' => dirname(ValidatedData::string($member['checkout_path'])),
                        ])
                        : $execution->descriptor("removal-{$id}-source-finalize", 'instance_lifecycle', 'instance.finalize-source-removal', [
                            ...$base, 'branch' => $member['branch'] ?? '', 'receipt' => $receipt,
                            'force' => $input['force'] || ! $member['runtime_published'] && $member['starting_commit'] === null,
                            'origin' => $member['origin'] ?? $input['repository'],
                            'worktrees' => $member['worktree_inventory'] ?? '', 'commit' => $member['source_commit'] ?? '',
                            'grouping_directory' => dirname(ValidatedData::string($member['checkout_path'])),
                            'unresolved' => $member['starting_commit'] === null && ! $member['runtime_published'],
                            'independent_worktree' => $input['force'] && $member['source_layout'] === 'worktree'
                                && count(array_filter(InstanceLifecycleInput::members($input), static fn (array $candidate): bool => $candidate['source_layout'] === 'checkout')) === 0,
                            'own_units' => $instance instanceof Instance ? RemoteDevelopmentInstanceSourceRemoval::ownUnits($instance) : [],
                            'git_read' => ['$secret' => 'instance.lifecycle_git', 'id' => $input['project_id']],
                        ]));
            }
            foreach (['route-clear', 'database-intent', 'runtime-intent', 'row-delete'] as $action) {
                $descriptors[] = $execution->descriptor("removal-{$id}-{$action}", 'gateway', 'instance.lifecycle-state', ['action' => 'removal-'.$action, 'operation_id' => $operation->id, 'instance_id' => $id]);
            }
        }

        return $descriptors;
    }

    /** @return array<string, mixed> */
    public function intent(Operation $operation): array
    {
        return isset($operation->input['members']) ? $operation->input : ValidatedData::object($operation->state['lifecycle_removal'] ?? $operation->input);
    }

    /** @param RunInput $run */
    public function beforeRun(Operation $operation, array $run): void
    {
        $execution = app(InstanceLifecycleExecution::class);
        if ($run['key'] === 'removal-revalidate') {
            foreach (InstanceLifecycleInput::members($this->intent($operation)) as $member) {
                if ($member['run_teardown'] === true && ($member['row_deleted_at'] ?? null) === null) {
                    $execution->assertStepList($operation, 'teardown-'.ValidatedData::integer($member['instance_id']));
                }
            }
        }
        if ($run['key'] === 'removal-accept') {
            $execution->proven($operation, 'removal-revalidate');
        }
        if (preg_match('/\Aremoval-([1-9][0-9]*)-(route-clear|route-detach|database-intent|source-prepare|source-finalize|runtime-intent|home-remove|row-delete)\z/D', $run['key'], $match) === 1) {
            $execution->proven($operation, 'removal-accept');
            $member = array_find(InstanceLifecycleInput::members($this->intent($operation)), static fn (array $member): bool => $member['instance_id'] === (int) $match[1]);
            $development = ($member['environment'] ?? null) !== 'production';
            $previous = match ($match[2]) {
                'route-clear' => "removal-{$match[1]}-source-prepare",
                'source-finalize' => $development ? "removal-{$match[1]}-database-intent" : "removal-{$match[1]}-route-clear",
                'database-intent' => "removal-{$match[1]}-runtime-intent",
                'runtime-intent' => $development ? "removal-{$match[1]}-route-clear" : "removal-{$match[1]}-source-finalize",
                'home-remove' => "removal-{$match[1]}-runtime-intent",
                'route-detach' => "removal-{$match[1]}-source-prepare",
                'row-delete' => $development ? "removal-{$match[1]}-source-finalize" : "removal-{$match[1]}-runtime-intent",
                default => null,
            };
            if ($previous !== null) {
                $execution->proven($operation, $previous);
            }
            if ($match[2] === 'database-intent') {
                app(InstanceRemovalRuntime::class)->assertRemoved($operation, (int) $match[1]);
                $execution->assertChild($operation, 'cleanup-instance-'.$match[1], 'route.cleanup_instance');
            }
            if (isset($operation->input['production_home'])) {
                app(ProductionRemovalIntent::class)->validate($operation);
                if ($match[2] === 'route-detach') {
                    foreach (app(ProductionRemovalIntent::class)->routes($operation) as $route) {
                        if (count(ValidatedData::integerList($route['targets'])) === 1) {
                            $execution->assertChild($operation, 'withdraw-production-'.$route['id'], 'route.withdraw');
                        }
                    }
                }
                if (in_array($match[2], ['home-remove', 'row-delete'], true)) {
                    $execution->assertChild($operation, 'cleanup-instance-'.$match[1], 'route.cleanup_instance');
                    if ($match[2] === 'row-delete') {
                        $execution->proven($operation, "removal-{$match[1]}-home-remove");
                    }
                }
            }
            if (in_array($match[2], ['home-remove', 'row-delete'], true) || $development && $match[2] === 'source-finalize') {
                app(InstanceRemovalRuntime::class)->assertRemoved($operation, (int) $match[1]);
                $execution->assertChild($operation, "remove-databases-{$match[1]}", 'database.destroy_owned');
            }
        }
    }

    /** @param RunInput $run
     * @param  array<string, mixed>  $receipt
     * @return array<string, mixed>
     */
    public function received(Operation $operation, array $run, array $receipt): array
    {
        if (isset($receipt['error_code'])) {
            return $receipt;
        }
        if (isset($operation->input['production_home']) && $run['transport'] === 'instance_lifecycle') {
            if ($run['name'] === 'instance.remove-production-home') {
                return is_bool($receipt['removed'] ?? null) ? ['home_removed' => true] : ['error_code' => 'app-prod.source_cleanup_failed'];
            }
            if ($run['name'] === 'instance.clear-current-release') {
                if (! array_key_exists('current', $receipt) || $receipt['current'] !== null) {
                    return ['error_code' => 'app-prod.source_metadata_unsafe'];
                }

                return app(ProductionRemovalIntent::class)->transition($operation, 'removal-production-finalize');
            }
        }
        if (preg_match('/\Aremoval-([1-9][0-9]*)-source-(prepare|finalize)\z/D', $run['key'], $match) !== 1 || $run['transport'] !== 'instance_lifecycle') {
            return $receipt;
        }
        $input = $this->intent($operation);
        $member = InstanceRemovalMember::query()->where('instance_removal_id', $input['removal_id'])->where('instance_id', (int) $match[1])->firstOrFail();
        if ($match[2] === 'prepare') {
            $member->update(['source_prepared_at' => now()]);

            return ['prepared' => true];
        }
        $removalId = ValidatedData::string($input['removal_id']);
        $expected = hash('sha256', "{$removalId}\0{$member->id}\0{$member->source_digest}\0finalized");
        if (($run['name'] !== 'instance.clean-removal-receipt' && ($receipt['state'] ?? null) !== 'finalized') || ($receipt['receipt'] ?? null) !== $expected) {
            return ['error_code' => ($receipt['state'] ?? null) === 'cross_device' ? 'instance.removal_incomplete' : 'instance.finalization_evidence_invalid'];
        }
        $member->update(['source_finalized_at' => now(), 'finalization_receipt' => $expected]);

        return ['finalized' => true, 'receipt' => $expected];
    }
}
