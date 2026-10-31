<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Registration source-set binding and receipt projection, without Node execution.
 *
 * @phpstan-import-type Descriptor from OperationFamilyExecution
 * @phpstan-import-type RunInput from \App\Domain\Operations\SwampOperationExecutor
 */
final readonly class RegistrationLifecycleExecution
{
    /** @return list<Descriptor> */
    public function descriptors(Operation $operation): array
    {
        $execution = app(InstanceLifecycleExecution::class);
        $members = InstanceLifecycleInput::members($operation->input);
        $inPlace = array_all($members, static fn (array $member): bool => $member['original_path'] === $member['checkout']);
        $prepared = array_all($members, static fn (array $member): bool => in_array($member['relocation_state'], ['destination_verified', 'original_cleanup', 'relocated'], true));
        $relocated = array_all($members, static fn (array $member): bool => $member['relocation_state'] === 'relocated');
        $payload = $this->payload($operation, false);
        $descriptors = [
            $prepared
                ? $execution->descriptor('registration-relocate-prepare', 'gateway', 'instance.lifecycle-state', ['action' => 'configuration', 'operation_id' => $operation->id])
                : $execution->descriptor('registration-relocate-prepare', 'instance_lifecycle', 'instance.relocate-registration-source', ['operation' => $inPlace ? 'adopt' : 'prepare', 'members' => $payload]),
            $relocated
                ? $execution->descriptor('registration-relocate-cleanup', 'gateway', 'instance.lifecycle-state', ['action' => 'configuration', 'operation_id' => $operation->id])
                : $execution->descriptor('registration-relocate-cleanup', 'instance_lifecycle', 'instance.relocate-registration-source', ['operation' => $inPlace ? 'adopt-finalize' : 'cleanup', 'members' => $this->payload($operation, true)]),
        ];
        foreach ($members as $member) {
            $instance = Instance::query()->with(['project', 'node'])->findOrFail(ValidatedData::integer($member['instance_id']));
            $id = $instance->id;
            if (! $member['completed']) {
                $descriptors[] = $execution->descriptor("registration-{$id}-receipt-prepare", 'instance_lifecycle', 'instance.laravel-registration-receipt', [
                    'checkout' => $member['checkout'], 'instance_id' => $id, 'operation' => 'prepare', 'application' => $instance->applicationDirectory(),
                ]);
                $descriptors[] = $execution->descriptor("registration-{$id}-profile", 'instance_lifecycle', 'instance.inspect-development-profile', [
                    'application' => $instance->applicationDirectory(), 'owner' => $instance->node->user,
                    'entry_point' => $instance->project->type->frameworkEntryPoint(),
                ]);
                $profile = $execution->recordedResult($operation, "registration-{$id}-profile");
                $descriptors[] = ($profile['laravel'] ?? false) && $member['route_id'] !== null
                    ? $execution->url($operation, "registration-{$id}-configuration", $instance, ValidatedData::string($instance->routes()->whereKey($member['route_id'])->value('domain')))
                    : $execution->descriptor("registration-{$id}-configuration", 'gateway', 'instance.lifecycle-state', ['action' => 'configuration', 'operation_id' => $operation->id]);
                $descriptors[] = $execution->descriptor("registration-{$id}-activate", 'gateway', 'instance.lifecycle-state', ['action' => 'registration-activate', 'operation_id' => $operation->id, 'instance_id' => $id]);
                $descriptors[] = $execution->descriptor("registration-{$id}-receipt-restore", 'instance_lifecycle', 'instance.laravel-registration-receipt', [
                    'checkout' => $member['checkout'], 'instance_id' => $id, 'operation' => 'restore', 'application' => $instance->applicationDirectory(),
                ], required: false);
            }
            $descriptors[] = $execution->descriptor("registration-{$id}-receipt-discard", 'instance_lifecycle', 'instance.laravel-registration-receipt', [
                'checkout' => $member['checkout'], 'instance_id' => $id, 'operation' => 'discard', 'application' => $instance->applicationDirectory(),
            ]);
        }

        return $descriptors;
    }

    /** @param RunInput $run */
    public function beforeRun(Operation $operation, array $run): void
    {
        $execution = app(InstanceLifecycleExecution::class);
        if ($run['key'] === 'registration-relocate-prepare') {
            foreach (InstanceLifecycleInput::members($operation->input) as $member) {
                if (! in_array($member['relocation_state'], ['destination_verified', 'original_cleanup', 'relocated'], true)) {
                    Instance::query()->whereKey($member['instance_id'])->update(['registration_relocation_state' => 'relocating', 'registration_authoritative_path' => $member['original_path']]);
                }
            }
        } elseif ($run['key'] === 'registration-relocate-cleanup') {
            $execution->proven($operation, 'registration-relocate-prepare');
        } elseif (preg_match('/\Aregistration-([1-9][0-9]*)-(.+)\z/D', $run['key'], $matches) === 1) {
            $execution->proven($operation, 'registration-relocate-cleanup');
            if ($matches[2] === 'receipt-restore' && ! $this->canRestore($operation, (int) $matches[1])) {
                throw $this->invalid();
            }
            if ($matches[2] === 'configuration') {
                $execution->proven($operation, "registration-{$matches[1]}-receipt-prepare");
                $execution->proven($operation, "registration-{$matches[1]}-profile");
            }
            if ($matches[2] === 'receipt-discard') {
                $member = array_find(InstanceLifecycleInput::members($operation->input), static fn (array $member): bool => $member['instance_id'] === (int) $matches[1]);
                if ($member === null || ! $member['completed']) {
                    $execution->proven($operation, "registration-{$matches[1]}-activate");
                }
            }
            if ($matches[2] === 'activate') {
                $member = array_find(InstanceLifecycleInput::members($operation->input), static fn (array $member): bool => $member['instance_id'] === (int) $matches[1]);
                if ($member !== null && $member['route_id'] !== null) {
                    $execution->assertChild($operation, 'route-'.$matches[1], 'route.converge');
                }
                $execution->proven($operation, "registration-{$matches[1]}-configuration");
            }
        }
    }

    public function canRestore(Operation $operation, int $id): bool
    {
        $policy = app(InstanceLifecycleChildren::class);
        $member = array_find(InstanceLifecycleInput::members($operation->input), static fn (array $member): bool => $member['instance_id'] === $id);
        $instance = Instance::query()->find($id);
        if ($operation->kind !== 'instance.register' || $operation->cancel_requested_at !== null
            || $member === null || $member['completed'] || $instance === null || $instance->status === InstanceState::Active
            || ! $policy->ready($operation, "registration-{$id}-receipt-prepare")) {
            return false;
        }
        foreach (["registration-{$id}-profile", "registration-{$id}-configuration"] as $key) {
            if ($policy->confirmedRefusal(app(InstanceLifecycleExecution::class)->recordedResult($operation, $key))) {
                return true;
            }
        }

        return $policy->confirmedRefusal($policy->stepOutcome($operation, "route-{$id}") ?? []);
    }

    public function assertFailure(Operation $operation, OperationOutcome $outcome): void
    {
        foreach (InstanceLifecycleInput::members($operation->input) as $member) {
            $id = ValidatedData::integer($member['instance_id']);
            if (! $this->canRestore($operation, $id)) {
                continue;
            }
            $execution = app(InstanceLifecycleExecution::class);
            $key = "registration-{$id}-receipt-restore";
            $result = $execution->recordedResult($operation, $key);
            if ($outcome->errorCode !== null && ($result['error_code'] ?? null) === $outcome->errorCode) {
                continue;
            }
            $execution->proven($operation, $key);
        }
    }

    /** @param RunInput $run
     * @param  array<string, mixed>  $receipt
     * @return array<string, mixed>
     */
    public function received(Operation $operation, array $run, array $receipt): array
    {
        if ($run['key'] === 'registration-relocate-prepare' && $run['transport'] === 'instance_lifecycle') {
            $members = InstanceLifecycleInput::members($operation->input);
            $inPlace = array_all($members, static fn (array $member): bool => $member['original_path'] === $member['checkout']);
            $identities = $receipt['identities'] ?? null;
            if (! is_array($identities) || ! $inPlace && count($identities) !== count($members)) {
                throw $this->invalid();
            }
            $indexed = [];
            foreach ($identities as $identity) {
                if (! is_array($identity) || ! is_int($identity['id'] ?? null) || isset($indexed[$identity['id']])
                    || (($identity['source_device'] ?? null) !== null || ($identity['source_inode'] ?? null) !== null)
                    && (! is_int($identity['source_device'] ?? null) || ! is_int($identity['source_inode'] ?? null))) {
                    throw $this->invalid();
                }
                $indexed[$identity['id']] = $identity;
            }
            if (! $inPlace) {
                $ids = array_keys($indexed);
                sort($ids);
                $expected = array_column($members, 'instance_id');
                sort($expected);
                if ($ids !== $expected) {
                    throw $this->invalid();
                }
            }
            DB::transaction(static function () use ($members, $indexed): void {
                foreach ($members as $member) {
                    $identity = $indexed[ValidatedData::integer($member['instance_id'])] ?? [];
                    Instance::query()->whereKey($member['instance_id'])->update([
                        'registration_relocation_state' => 'destination_verified', 'registration_authoritative_path' => $member['checkout'],
                        'registration_source_device' => $identity['source_device'] ?? null, 'registration_source_inode' => $identity['source_inode'] ?? null,
                    ]);
                }
            });

            return ['prepared' => true];
        }
        if ($run['key'] === 'registration-relocate-cleanup' && $run['transport'] === 'instance_lifecycle') {
            foreach (InstanceLifecycleInput::members($operation->input) as $member) {
                Instance::query()->whereKey($member['instance_id'])->update([
                    'registration_relocation_state' => 'relocated', 'registration_authoritative_path' => $member['checkout'],
                ]);
            }

            return ['relocated' => true];
        }
        if (preg_match('/\Aregistration-([1-9][0-9]*)-profile\z/D', $run['key'], $match) === 1) {
            $member = collect(InstanceLifecycleInput::members($operation->input))->firstWhere('instance_id', (int) $match[1]);
            if ($member === null) {
                throw $this->invalid();
            }
            $memberOperation = clone $operation;
            $memberOperation->input = [...$operation->input, 'instance_id' => $member['instance_id'], 'checkout' => $member['checkout']];
            $instance = Instance::query()->findOrFail(ValidatedData::integer($member['instance_id']));
            if ($instance->status === InstanceState::Reserved) {
                $instance->update(['status' => InstanceState::SourceResolved, 'failed_step' => null, 'error_code' => null]);
            }

            return app(InstanceLifecycleExecution::class)->classifyProfile($memberOperation, $receipt);
        }

        return $receipt;
    }

    /** @return list<array<string, mixed>> */
    private function payload(Operation $operation, bool $prepared): array
    {
        $payload = [];
        foreach (InstanceLifecycleInput::members($operation->input) as $member) {
            $instance = Instance::query()->with('project')->findOrFail(ValidatedData::integer($member['instance_id']));
            $payload[] = [
                'id' => $instance->id, 'source' => $member['original_path'], 'destination' => $member['checkout'],
                'application' => $instance->applicationDirectory(), 'layout' => $member['source_layout'],
                'commit' => $member['commit'], 'branch' => $member['branch'], 'detached' => $member['detached'],
                'digest' => $member['source_digest'], 'common' => $member['common_repository_path'], 'worktrees' => $member['worktree_paths'],
                'source_device' => $prepared ? $instance->registration_source_device : $member['source_device'],
                'source_inode' => $prepared ? $instance->registration_source_inode : $member['source_inode'],
            ];
        }

        return $payload;
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('instance.registration_incomplete', 'Registration relocation returned invalid source-set evidence.', 409);
    }
}
