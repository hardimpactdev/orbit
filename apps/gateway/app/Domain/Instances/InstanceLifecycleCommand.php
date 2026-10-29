<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Domain\Instances\Environment\CloneEnvironmentWarnings;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Schedule;
use App\Support\ValidatedData;

/** Named Gateway state transitions. It never invokes a Node transport. */
final readonly class InstanceLifecycleCommand implements OperationGatewayCommand
{
    public function __construct(private InstanceLifecycleState $state) {}

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false,
            'required' => ['action', 'operation_id'], 'properties' => [
                'action' => ['type' => 'string', 'enum' => [
                    'configuration', 'clone-checkpoint', 'activate', 'finish', 'clone-environment', 'clone-sqlite-intent', 'clone-sqlite-attach', 'clone-definitions', 'registration-activate',
                    'removal-production-prepare', 'removal-production-finalize', 'removal-route-detach', 'removal-inspect', 'removal-revalidate', 'removal-accept', 'removal-route-clear', 'removal-database-intent', 'removal-runtime-intent', 'removal-row-delete',
                    'transfer-cutover', 'transfer-finish', 'transfer-sqlite-intent', 'database-intent',
                ]],
                'operation_id' => ['type' => 'integer', 'minimum' => 1],
                'instance_id' => ['type' => 'integer', 'minimum' => 1],
            ]];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        if ($input['operation_id'] !== $operation->id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The state transition belongs to another Operation.', 422);
        }

        return match ($input['action']) {
            'configuration' => ['written' => true],
            'clone-checkpoint' => $this->cloneCheckpoint($operation),
            'activate' => $this->activate($operation),
            'clone-environment' => $this->copyEnvironment($operation),
            'clone-sqlite-intent' => app(InstanceSqliteSeedIntent::class)->prepareClone($operation),
            'clone-sqlite-attach' => app(InstanceSqliteSeedIntent::class)->attachClone($operation),
            'clone-definitions' => $this->captureDefinitions($operation),
            'registration-activate' => $this->registrationActivate($operation, ValidatedData::integer($input['instance_id'])),
            'removal-production-prepare', 'removal-production-finalize', 'removal-route-detach', 'removal-inspect', 'removal-revalidate', 'removal-accept', 'removal-route-clear', 'removal-database-intent', 'removal-runtime-intent', 'removal-row-delete' => app(InstanceRemovalState::class)->transition($operation, $input),
            'database-intent' => app(InstanceDatabaseIntent::class)->record($operation),
            'finish' => $this->finish($operation),
            default => throw new ResourceOperationException('operation.execution_forbidden', 'Unknown lifecycle state transition.', 422),
        };
    }

    /** @return array<string, mixed> */
    private function cloneCheckpoint(Operation $operation): array
    {
        $instance = $this->state->instance($operation);
        if ($operation->kind !== 'instance.clone' || $instance->clone_candidate_id !== $operation->input['candidate_id']
            || $instance->branch !== $operation->input['branch'] || $instance->provisioning_step !== $operation->input['initial_provisioning_step']) {
            throw new ResourceOperationException('instance.lifecycle_conflict', 'The retained clone checkpoint changed.', 409);
        }

        return ['prepared' => true];
    }

    /** @return array<string, mixed> */
    private function activate(Operation $operation): array
    {
        $this->state->activated($operation, true);

        return ['activated' => true];
    }

    /** @return array<string, mixed> */
    private function copyEnvironment(Operation $operation): array
    {
        $instance = $this->state->instance($operation);
        $candidate = Instance::query()->findOrFail(ValidatedData::integer($operation->input['candidate_id']));
        $contexts = app(InstanceEnvironmentContextResolver::class);
        $store = app(InstanceEnvironmentStore::class);
        $store->copyForClone($contexts->resolve($candidate, true), $contexts->resolveForClone($instance, true));
        $store->forceAppProdMode($instance);
        $instance->update(['provisioning_step' => 'clone-environment-synchronized']);

        return ['environment_copied' => true];
    }

    /** @return array<string, mixed> */
    private function captureDefinitions(Operation $operation): array
    {
        $instance = $this->state->instance($operation);
        app(InstantiateProjectRuntimeDefinitionsAction::class)->captureForClone($instance);
        $instance->update(['provisioning_step' => 'clone-definitions-instantiated']);

        return [
            'definitions_captured' => true,
            'process_ids' => Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)->whereNotNull('source_definition_id')->orderBy('id')->pluck('id')->all(),
            'schedule_ids' => Schedule::query()->whereIn('target_type', Instance::morphTypes())->where('target_id', $instance->id)->whereNotNull('source_definition_id')->orderBy('id')->pluck('id')->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function registrationActivate(Operation $operation, int $id): array
    {
        if (! in_array($id, array_column(InstanceLifecycleInput::members($operation->input), 'instance_id'), true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Instance is outside the admitted registration set.', 422);
        }
        Instance::query()->whereKey($id)->update(['status' => InstanceState::Active, 'provisioning_step' => 'active',
            'registration_completed_at' => now(), 'failed_step' => null, 'error_code' => null]);

        return ['activated' => true, 'instance_id' => $id];
    }

    /** @return array<string, mixed> */
    private function finish(Operation $operation): array
    {
        app(InstanceLifecycleExecution::class)->assertEvidence($operation);
        if ($operation->kind === 'instance.destroy') {
            $intent = app(RemovalLifecycleExecution::class)->intent($operation);
            $removal = InstanceRemoval::query()->findOrFail(ValidatedData::string($intent['removal_id']));
            if ($removal->members()->whereNull('row_deleted_at')->exists()) {
                throw new ResourceOperationException('operation.completion_unproven', 'The admitted removal set is incomplete.', 409);
            }

            return ['lifecycle_complete' => true, 'instance_id' => $intent['instance_id'], 'removal_id' => $removal->id, 'status' => 'completed', 'total' => $removal->total, 'completed' => $removal->total, 'remaining' => 0];
        }
        $instance = $this->state->instance($operation);
        if ($operation->kind === 'instance.rename') {
            $source = app(InstanceLifecycleExecution::class)->proven($operation, 'rename-inspect');
            $this->state->renamed($operation, $source);
        } elseif ($operation->kind === 'instance.create'
            && ! (($operation->input['initial_status'] ?? null) === 'active' && ($operation->input['first_setup_pending'] ?? true) === false)) {
            $instance->update(['status' => InstanceState::Active, 'provisioning_step' => 'active', 'first_setup_pending' => false, 'failed_step' => null, 'error_code' => null]);
        } elseif ($operation->kind === 'instance.clone') {
            $instance->update(['status' => InstanceState::Active, 'provisioning_step' => 'active', 'clone_completed_at' => $instance->clone_completed_at ?? now(), 'failed_step' => null, 'error_code' => null]);
        }
        $result = ['lifecycle_complete' => true, 'instance_id' => $instance->id, 'project_id' => $instance->project_id, 'name' => $instance->name,
            'status' => $instance->refresh()->status->value, 'branch' => $instance->branch,
            'created' => $operation->input['created'] ?? false, 'name_converted_from' => $operation->input['name_converted_from'] ?? null];
        if ($operation->kind === 'instance.clone') {
            $candidate = Instance::query()->with('node')->findOrFail(ValidatedData::integer($operation->input['candidate_id']));
            $instance->loadMissing('node');
            $result['warnings'] = app(CloneEnvironmentWarnings::class)->detect(app(InstanceEnvironmentStore::class)->values($instance), $candidate->node, (string) $instance->production_home, $instance->node);
        }
        if ($operation->kind === 'instance.register') {
            $result['instance_ids'] = array_column(InstanceLifecycleInput::members($operation->input), 'instance_id');
        }

        return $result;
    }
}
