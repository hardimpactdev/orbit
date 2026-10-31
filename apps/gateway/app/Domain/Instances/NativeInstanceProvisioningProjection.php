<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentMaterial;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Assign the bound native source and final outcome. The Gateway does not replay execution proofs. */
final readonly class NativeInstanceProvisioningProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        $input['git_read'] = ['$secret' => 'instance.lifecycle_git', 'id' => $input['project_id']];
        $input['environment_material'] = ['$secret' => 'instance.environment_sync', 'id' => $operation->target_id];
        if ($operation->kind === 'instance.create') {
            $input['setup_programs'] = ['$secret' => 'instance.provisioning_setup', 'id' => $operation->target_id];
        }
        $instance = Instance::query()->find($operation->target_id);
        if (! $operation->status->terminal() && $instance?->status === InstanceState::SourceResolved
            && in_array($instance->provisioning_step, ['php-selected', 'clone-source-classified'], true)) {
            $input['environment_plan'] = app(NativeInstanceEnvironmentMaterial::class)->plan($operation);
        }

        return $input;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->refresh();
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $result = ValidatedData::object($data['result'] ?? []);
            $status = $data['status'] ?? null;
            if (! in_array($operation->kind, ['instance.create', 'instance.clone'], true)) {
                $this->invalid();
            }
            $instance = Instance::query()->where('node_id', $operation->node_id)
                ->where('project_id', $operation->input['project_id'] ?? null)
                ->lockForUpdate()->findOrFail($operation->target_id);
            if (! $operation->status->terminal()) {
                if ($status === 'running' && ($result['phase'] ?? null) === 'source') {
                    $this->source($operation, $instance, $result);
                } elseif ($status === 'running' && ($result['phase'] ?? null) === 'environment') {
                    if (array_diff(array_keys($result), ['phase', 'keys']) !== [] || ! is_array($result['keys'] ?? null)
                        || ! array_is_list($result['keys']) || array_any($result['keys'], static fn (mixed $key): bool => ! is_string($key))) {
                        $this->invalid();
                    }
                    $instance->update(['environment_owned_keys' => array_values(array_unique($result['keys']))]);
                } elseif ($status === 'running' && ($result['phase'] ?? null) === 'vp') {
                    if (array_diff(array_keys($result), ['phase', 'environment']) !== [] || ! array_key_exists('environment', $result)
                        || ! (is_array($result['environment']) || $result['environment'] === null)) {
                        $this->invalid();
                    }
                    if (is_array($result['environment'])) {
                        $instance->update(['vp_environment' => [...$result['environment'], 'status' => 'verified', 'checkout_path' => $instance->checkout_path]]);
                    }
                } elseif ($status === 'succeeded') {
                    if ($result !== ['instance_id' => $instance->id, 'ready' => true]) {
                        $this->invalid();
                    }
                    $instance->update(['status' => InstanceState::Active, 'provisioning_step' => 'active',
                        'first_setup_pending' => false, 'failed_step' => null, 'error_code' => null,
                        ...($operation->kind === 'instance.clone' ? ['clone_completed_at' => now()] : [])]);
                } elseif ($status === 'failed') {
                    if (! is_string($result['failed_step'] ?? null) || ! is_string($data['error_code'] ?? null)) {
                        $this->invalid();
                    }
                    $instance->update(['failed_step' => $result['failed_step'], 'error_code' => $data['error_code']]);
                } elseif (! in_array($status, ['running', 'cancelled'], true) || $result !== []) {
                    $this->invalid();
                }
            }

            return $this->direct->project($operation, $data, $result);
        });
    }

    /** @param array<string,mixed> $source */
    private function source(Operation $operation, Instance $instance, array $source): void
    {
        if (array_diff(array_keys($source), ['phase', 'branch', 'commit', 'php_version', 'laravel', 'candidate_commit']) !== []
            || ! is_string($source['branch'] ?? null) || ! is_string($source['commit'] ?? null)
            || ! is_bool($source['laravel'] ?? null)
            || ! array_key_exists('php_version', $source)
            || ! (is_string($source['php_version']) || $source['php_version'] === null)) {
            $this->invalid();
        }
        $instance->update(['status' => InstanceState::SourceResolved, 'branch' => $source['branch'],
            'starting_commit' => $source['commit'], 'selected_php_version' => $source['php_version'],
            'source_is_laravel' => $source['laravel'],
            'provisioning_step' => $operation->kind === 'instance.create' ? 'php-selected' : 'clone-source-classified',
            'failed_step' => null, 'error_code' => null,
            ...($operation->kind === 'instance.create' && ($operation->input['seed_commit'] ?? null) !== null
                ? ['source_layout' => InstanceSourceLayout::Worktree] : [])]);
        if ($operation->kind === 'instance.clone') {
            $instance->update(['clone_candidate_commit' => $source['candidate_commit'] ?? null]);
            $candidate = Instance::query()->findOrFail(ValidatedData::integer($operation->input['candidate_id']));
            $contexts = app(InstanceEnvironmentContextResolver::class);
            $store = app(InstanceEnvironmentStore::class);
            $store->copyForClone($contexts->resolve($candidate, true), $contexts->resolveForClone($instance, true));
            $store->forceAppProdMode($instance);
        }
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('operation.result_invalid', 'Instance provisioning requires its bounded native phase result.', 422);
    }
}
