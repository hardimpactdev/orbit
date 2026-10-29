<?php

declare(strict_types=1);

namespace App\Domain\Instances\PhpRuntime;

use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceExecutionEvidence;
use App\Domain\Instances\Environment\InstanceRouteOwnership;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;

/** A Route's PHP pool preparation, with the activating web root included before publication.
 * @phpstan-import-type Descriptor from OperationFamilyExecution
 */
final readonly class InstancePhpRuntimeExecution implements OperationCompletion, OperationFamilyChildren, OperationFamilyExecution
{
    public function __construct(private InstanceRouteOwnership $ownership, private InstanceDeploymentIntent $intents, private OperationStore $operations) {}

    /** @return array{runtime:array<string,mixed>|null,profile:array<string,mixed>|null} */
    public function plan(Operation $operation): array
    {
        $route = $this->ownership->route($operation);
        if ($route === null) {
            throw $this->unproven();
        }
        $instance = Instance::query()->with(['node', 'project'])->findOrFail($operation->target_id);
        $activating = $this->ownership->preparingRoute($operation);
        [$runtime] = $this->intents->runtimeIntent($instance, $activating);

        return ['runtime' => $runtime, 'profile' => $runtime === null ? null
            : app(RemotePhpPackageManager::class)->runtimeInputForAppProd($instance->node, $instance->selected_php_version ?? throw $this->unproven())];
    }

    public function context(Operation $operation): array
    {
        if ($this->ownership->route($operation) === null) {
            throw $this->unproven();
        }
        $descriptors = [$this->descriptor('php.plan', 'php_plan', 'gateway', 'instance.php-plan', ['instance_id' => $operation->target_id])];
        $plan = $operation->state['php_plan'] ?? null;
        if (is_array($plan) && is_array($plan['runtime'] ?? null)) {
            $descriptors[] = $this->descriptor('child.php_packages', 'php_packages', 'gateway', 'instance.child-receipt', ['child_key' => 'php_packages']);
            $descriptors[] = $this->descriptor('php.profile', 'php_converge.profile', 'instance-deploy-agent', 'php.configure-runtime', ValidatedData::object($plan['profile']));
            $descriptors[] = $this->descriptor('php.converge', 'php_converge', 'instance-deploy-agent', 'php.converge-production-runtime', ValidatedData::object($plan['runtime']));
        }
        $descriptors[] = $this->descriptor('php.receipt', 'php_receipt', 'gateway', 'instance.php-receipt', ['instance_id' => $operation->target_id]);

        return ['descriptors' => $descriptors];
    }

    public function childContext(Operation $parent): array
    {
        $plan = ValidatedData::object($parent->state['php_plan'] ?? []);
        $children = [];
        $prepared = InstanceExecutionEvidence::result($parent, 'php.plan');
        if ($prepared !== null && ($plan['runtime'] ?? null) !== null) {
            $children[] = ['key' => 'php_packages', 'kind' => 'node.prepare_php_packages', 'node_id' => $parent->node_id,
                'input' => ['versions' => [ValidatedData::string(ValidatedData::object($plan['runtime'])['version'])], 'profile' => 'app-prod']];
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
        if ($run['key'] === 'php.plan') {
            if (! array_key_exists('runtime', $receipt) || ($receipt['runtime'] !== null && ! is_array($receipt['runtime']))) {
                throw $this->unproven();
            }
            $this->operations->change($operation, ['state' => [...($operation->state ?? []), 'php_plan' => $receipt]]);

            return ['prepared' => true, 'has_runtime' => $receipt['runtime'] !== null];
        }
        if ($run['key'] === 'php.profile') {
            if (! is_bool($receipt['changed'] ?? null)) {
                throw $this->unproven();
            }

            return ['changed' => $receipt['changed']];
        }
        if ($run['key'] === 'php.converge' && ! is_bool($receipt['published'] ?? null)) {
            throw $this->unproven();
        }

        return $receipt;
    }

    /** @return array{instance_id:int,confirmed:true,no_op:bool} */
    public function result(Operation $operation): array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] !== 'php.receipt') {
                $this->proof($operation, $descriptor);
            }
        }
        $plan = ValidatedData::object($operation->state['php_plan'] ?? []);

        return ['instance_id' => $operation->target_id, 'confirmed' => true, 'no_op' => $plan['runtime'] === null];
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
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    /** @param Descriptor $descriptor */
    private function proof(Operation $operation, array $descriptor): void
    {
        if ($descriptor['name'] === 'instance.child-receipt') {
            app(OperationChildren::class)->requireSucceeded($operation, ValidatedData::string($descriptor['input']['child_key']));
        }
        $run = InstanceExecutionEvidence::run($operation, $descriptor['key']);
        if (($run['name'] ?? null) !== $descriptor['name'] || ($run['transport'] ?? null) !== $descriptor['transport']
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
        return new ResourceOperationException('operation.completion_unproven', 'The Route PHP runtime needs proven bound results.', 409);
    }
}
