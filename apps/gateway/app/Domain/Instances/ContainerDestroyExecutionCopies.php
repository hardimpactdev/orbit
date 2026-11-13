<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Infrastructure\Swamp\SwampScheduleWorkflows;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Delete the Instance's copies only after native destruction. Failure retains records for a fresh retry. */
final readonly class ContainerDestroyExecutionCopies
{
    public function __construct(private SwampScheduleWorkflows $vault) {}

    public function remove(Operation $destroy): void
    {
        $keys = [];
        foreach (Operation::query()->where('target_type', 'instance')->where('target_id', $destroy->target_id)
            ->whereIn('kind', ['instance.create', 'instance.destroy', 'instance.deploy', 'instance.migrate'])->get() as $operation) {
            $keys = [...$keys, ...$this->references($operation->input), ...$this->references($operation->result ?? [])];
            // These run-start copies are deterministic, rather than persisted in the public Operation input.
            foreach (['instance-git-operation-', 'instance-git-credential-'] as $prefix) {
                $keys[] = $prefix.$operation->id;
            }
            if ($operation->kind === 'instance.create') {
                foreach (['server_database', 'server_valkey'] as $child) {
                    $server = ValidatedData::object(ValidatedData::object($operation->input['direct_provisioning'] ?? [])[$child] ?? []);
                    if (isset($server['intent_id'])) {
                        $intent = ValidatedData::integer($server['intent_id']);
                        $keys[] = 'database-create-intent-'.$intent;
                        $keys[] = 'database-create-owner-'.$intent;
                    }
                }
                foreach (['instance-create-environment-input-', 'instance-create-environment-testing-', 'instance-create-model-', 'instance-create-processes-'] as $prefix) {
                    $keys[] = $prefix.$operation->id;
                }
            }
            if ($operation->kind === 'instance.migrate') {
                foreach (['instance-migrate-model-', 'instance-migrate-processes-', 'instance-migrate-source-'] as $prefix) {
                    $keys[] = $prefix.$operation->id;
                }
            }
            $database = ValidatedData::object(ValidatedData::object($operation->input['direct_destroy'] ?? [])['database'] ?? []);
            foreach (InstanceLifecycleInput::members(['members' => $database['servers'] ?? []]) as $server) {
                foreach (['fence', 'drop', 'gone', 'restore'] as $batch) {
                    $keys[] = 'database-destroy-owned-'.ValidatedData::integer($database['intent_id']).'-'.ValidatedData::integer($server['server_id']).'-'.$batch;
                }
            }
            if (in_array($operation->kind, ['instance.create', 'instance.deploy'], true)) {
                foreach (['credential', 'processes'] as $suffix) {
                    $keys[] = 'development-deploy-operation-'.$operation->id.'-'.$suffix;
                }
                $deployment = ValidatedData::object($operation->input['deployment'] ?? []);
                $container = ValidatedData::object(ValidatedData::object($operation->input['direct_provisioning'] ?? [])['container'] ?? []);
                foreach (array_keys(InstanceLifecycleInput::members(['members' => $deployment['steps'] ?? $container['steps'] ?? []])) as $step) {
                    $keys[] = 'development-deploy-operation-'.$operation->id.'-step-'.$step;
                }
            }
        }
        // One connection for every key: serve refuses a burst of connections from the Gateway's address.
        $this->vault->deleteSecrets('orbit-control-inputs', array_values(array_unique($keys)));
    }

    /** @param array<array-key, mixed> $data
     * @return list<string>
     */
    private function references(array $data): array
    {
        $keys = [];
        if (($data['vault'] ?? null) === 'orbit-control-inputs' && is_string($data['key'] ?? null)) {
            $keys[] = $data['key'];
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $keys = [...$keys, ...$this->references($value)];
            }
        }

        return $keys;
    }
}
