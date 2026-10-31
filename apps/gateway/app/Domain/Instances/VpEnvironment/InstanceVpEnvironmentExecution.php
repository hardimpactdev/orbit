<?php

declare(strict_types=1);

namespace App\Domain\Instances\VpEnvironment;

use App\Domain\Instances\Deployment\InstanceExecutionEvidence;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\VpEnvironment;
use App\Domain\Tools\VpManagerVersion;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Binds runtime effects to a proven deployment checkout; Swamp controls the install sequence.
 * @phpstan-import-type Descriptor from OperationFamilyExecution
 */
final readonly class InstanceVpEnvironmentExecution implements OperationCompletion, OperationFamilyChildren, OperationFamilyExecution
{
    public function __construct(private InstanceVpEnvironmentMaterial $material, private OperationStore $operations) {}

    public function context(Operation $operation): array
    {
        [$instance] = $this->material->target($operation);
        $descriptors = [$this->descriptor('vp.plan', 'vp_plan', 'gateway', 'instance.vp-plan', ['instance_id' => $instance->id])];
        $plan = $operation->state['vp_plan'] ?? null;
        if (is_array($plan) && $plan['requirements'] !== null) {
            $descriptors[] = $this->descriptor('child.vp_manager', 'vp_manager', 'gateway', 'instance.child-receipt', ['child_key' => 'vp_manager']);
            $descriptors[] = $this->descriptor('vp.scope', 'vp_scope', 'instance-deploy-agent', 'tool.inspect-vp-scope', []);
            $descriptors[] = $this->descriptor('vp.version', 'vp_version', 'instance-deploy-agent', 'tool.vp-version', []);
            $environment = $this->environment($operation);
            if ($environment !== null) {
                $descriptors[] = $this->descriptor('vp.inspect', 'vp_inspect', 'instance-deploy-agent', 'tool.inspect-vp-store', ['environments' => [$environment->input()]]);
                if ((InstanceExecutionEvidence::result($operation, 'vp.inspect')['installed'] ?? null) === false) {
                    $descriptors[] = $this->descriptor('vp.install', 'vp_install', 'instance-deploy-agent', 'tool.install-vp-environment', [
                        'environment' => $environment->input(), 'npmrc' => ['$secret' => 'instance.vp_environment', 'id' => $instance->id]]);
                }
                $descriptors[] = $this->descriptor('vp.verify', 'vp_verify', 'instance-deploy-agent', 'tool.inspect-vp-store', ['environments' => [$environment->input()]]);
                if (InstanceExecutionEvidence::result($operation, 'vp.install') !== null) {
                    $descriptors[] = $this->descriptor('vp.prune', 'vp_prune', 'instance-deploy-agent', 'tool.prune-vp-store', [
                        'store' => $environment->store, 'references' => $this->material->references($operation->node_id ?? throw $this->unproven(), $environment->store),
                    ]);
                }
            }
        }
        $descriptors[] = $this->descriptor('vp.receipt', 'vp_receipt', 'gateway', 'instance.vp-receipt', ['instance_id' => $instance->id]);

        return ['descriptors' => $descriptors];
    }

    public function childContext(Operation $parent): array
    {
        $plan = ValidatedData::object($parent->state['vp_plan'] ?? []);
        $children = [];
        $prepared = InstanceExecutionEvidence::result($parent, 'vp.plan');
        if ($prepared !== null && ($plan['requirements'] ?? null) !== null) {
            $children[] = ['key' => 'vp_manager', 'kind' => 'tool.manager_converge', 'node_id' => $parent->node_id,
                'input' => ['manager' => 'vp', 'image_provides_manager' => false]];
        }

        return ['intent' => [], 'children' => $children];
    }

    public function input(Operation $operation, array $run): array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === $run['key'] && $descriptor['name'] === $run['name'] && $descriptor['transport'] === $run['transport']) {
                return $descriptor['input'];
            }
        }
        throw $this->unproven();
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $this->material->target($operation);
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === $run['key']) {
                return;
            }
            $this->proof($operation, $descriptor);
        }
        throw $this->unproven();
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if (isset($receipt['error_code'])) {
            return $receipt;
        }
        if ($run['key'] === 'vp.plan') {
            $this->operations->change($operation, ['state' => [...($operation->state ?? []), 'vp_plan' => $receipt]]);
        }
        if ($run['key'] === 'vp.scope') {
            $binary = $receipt['binary'] ?? null;
            if (! is_string($binary) || ! str_starts_with($binary, '/') || ! str_ends_with($binary, '/bin/vp')) {
                throw $this->unproven();
            }
            $environment = VpEnvironment::fromArray([...ValidatedData::object(ValidatedData::object($operation->state['vp_plan'] ?? [])['requirements']), 'store' => dirname($binary, 2)]);
            $this->operations->change($operation, ['state' => [...($operation->state ?? []), 'vp_environment' => $environment->record('checking')]]);
            $this->material->reference($operation, $environment, 'checking');
        }
        if ($run['key'] === 'vp.version') {
            $version = $receipt['version'] ?? null;
            $required = $this->environment($operation)?->vpVersion;
            if (! is_string($version) || $required === null || ! VpManagerVersion::satisfies($version, $required)) {
                return ['error_code' => 'deployment.vp_store_failed'];
            }
        }
        if (in_array($run['key'], ['vp.inspect', 'vp.verify'], true)) {
            $installed = $receipt['installed'] ?? null;
            if (! is_array($installed) || count($installed) !== 1 || ! is_bool($installed[0] ?? null)) {
                throw $this->unproven();
            }
            if ($run['key'] === 'vp.verify') {
                if (! $installed[0]) {
                    return ['error_code' => 'deployment.vp_store_failed'];
                }
                $this->material->reference($operation, $this->environment($operation) ?? throw $this->unproven(), 'active');
            }

            return ['installed' => $installed[0]];
        }
        if ($run['key'] === 'vp.install') {
            return ['installed' => false];
        }

        return $receipt;
    }

    /** @return array{confirmed:true,instance_id:int,no_op:bool,vp_environment:?array<string,?string>} */
    public function result(Operation $operation): array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] !== 'vp.receipt') {
                $this->proof($operation, $descriptor);
            }
        }
        $plan = ValidatedData::object($operation->state['vp_plan'] ?? []);

        return ['confirmed' => true, 'instance_id' => $operation->target_id, 'no_op' => $plan['requirements'] === null,
            'vp_environment' => $this->environment($operation)?->input()];
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Succeeded && $outcome->result !== $this->result($operation)) {
            throw $this->unproven();
        }
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $this->assertCompletion($operation, $outcome);
        $environment = $this->environment($operation);
        if ($outcome->status !== OperationStatus::Succeeded && $environment !== null) {
            $this->material->reference($operation, $environment, 'failed');
        }
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    private function environment(Operation $operation): ?VpEnvironment
    {
        $value = $operation->state['vp_environment'] ?? null;

        return is_array($value) ? VpEnvironment::fromArray($value) : null;
    }

    /** @param Descriptor $descriptor */
    private function proof(Operation $operation, array $descriptor): void
    {
        if ($descriptor['name'] === 'instance.child-receipt') {
            app(OperationChildren::class)->requireSucceeded($operation, ValidatedData::string($descriptor['input']['child_key']));
        }
        $run = InstanceExecutionEvidence::run($operation, $descriptor['key']);
        if (! is_array($run) || ($run['name'] ?? null) !== $descriptor['name'] || ($run['transport'] ?? null) !== $descriptor['transport']
            || ($run['step'] ?? null) !== $descriptor['step'] || ! is_array($run['result'] ?? null) || isset($run['result']['error_code'])
            || ! $operation->steps()->where('name', $descriptor['step'])->where('status', 'succeeded')->exists()
            || InstanceExecutionEvidence::step($operation, $descriptor['step']) !== $run['result']) {
            throw $this->unproven();
        }
    }

    /** @param array<string,mixed> $input
     * @return Descriptor
     */
    private function descriptor(string $key, string $step, string $transport, string $name, array $input): array
    {
        return compact('key', 'step', 'transport', 'name', 'input') + ['resumable' => true, 'required' => true];
    }

    private function unproven(): ResourceOperationException
    {
        return new ResourceOperationException('operation.completion_unproven', 'The Project runtime needs proven Vite+ prerequisite results.', 409);
    }
}
