<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Instances\Deployment\InstanceExecutionEvidence;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\ProductionApplicationPaths;
use App\Models\Instance;
use App\Models\Operation;

/** The Route parent owns the pending web root; the Node prepares its selected release. */
final readonly class InstanceServedWebRootsExecution implements OperationCompletion, OperationFamilyExecution
{
    public function __construct(private InstanceRouteOwnership $ownership) {}

    public function context(Operation $operation): array
    {
        $route = $this->ownership->route($operation) ?? throw $this->invalid();
        $instance = Instance::query()->with(['project', 'node'])->findOrFail($operation->target_id);
        $user = $instance->production_user;
        $home = $instance->production_home;
        $root = $instance->root ?? $instance->project->root;
        if (! $instance->placedOnAppProd() || ! is_string($user) || ! is_string($home) || $home !== '/home/'.$user || ! is_string($root)) {
            throw $this->invalid();
        }
        $activating = $this->ownership->preparingRoute($operation);
        $served = array_map(static fn (array $application): array => ['web_root' => $application['web_root'], 'directory' => $application['directory']], RouteWebRoot::servedApplications($instance, $activating));
        if ($activating !== null && RouteWebRoot::poolSuffix($instance, $activating->web_root) !== null
            && ! array_any($served, static fn (array $application): bool => $application['web_root'] === $activating->web_root)) {
            $served[] = ['web_root' => $activating->web_root, 'directory' => RouteWebRoot::relativeDirectory($activating->web_root)];
        }
        $descriptors = [];
        if ($served !== []) {
            $descriptors[] = ['key' => 'web_roots.prepare', 'step' => 'web_roots', 'transport' => 'instance-deploy-agent', 'name' => 'instance.prepare-served-web-roots',
                'input' => ['repository' => $instance->project->repository_url, 'user' => $user, 'home' => $home, 'instance' => $instance->id,
                    'relative_root' => $root, 'application_suffix' => ProductionApplicationPaths::suffix($root), 'served' => $served], 'resumable' => true, 'required' => true];
        }
        $descriptors[] = ['key' => 'verification', 'step' => 'verification', 'transport' => 'gateway', 'name' => 'instance.web-roots-receipt',
            'input' => ['instance_id' => $instance->id], 'resumable' => true, 'required' => true];

        return ['descriptors' => $descriptors];
    }

    public function input(Operation $operation, array $run): array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === $run['key'] && $descriptor['name'] === $run['name'] && $descriptor['transport'] === $run['transport']) {
                return $descriptor['input'];
            }
        }
        throw $this->invalid();
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        if ($run['key'] === 'verification') {
            $this->result($operation);
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if ($run['key'] === 'web_roots.prepare' && isset($receipt['error_code'])) {
            return ['error_code' => $receipt['error_code'] === 'instance.release_missing' ? 'route.web_root_release_missing' : $receipt['error_code']];
        }

        return $receipt;
    }

    /** @return array{instance_id:int,confirmed:true,no_op:bool} */
    public function result(Operation $operation): array
    {
        $descriptors = $this->context($operation)['descriptors'];
        foreach ($descriptors as $descriptor) {
            if ($descriptor['key'] === 'verification') {
                continue;
            }
            $run = InstanceExecutionEvidence::run($operation, $descriptor['key']);
            if (($run['transport'] ?? null) !== $descriptor['transport'] || ($run['name'] ?? null) !== $descriptor['name']
                || ($run['step'] ?? null) !== $descriptor['step'] || ! is_array($run['result'] ?? null) || isset($run['result']['error_code'])
                || ! $operation->steps()->where('name', $descriptor['step'])->where('status', 'succeeded')->exists()
                || InstanceExecutionEvidence::step($operation, $descriptor['step']) !== $run['result']) {
                throw new ResourceOperationException('operation.completion_unproven', 'Served web roots need the bound preparation receipt.', 409);
            }
        }

        return ['instance_id' => $operation->target_id, 'confirmed' => true, 'no_op' => count($descriptors) === 1];
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Succeeded && $outcome->result !== $this->result($operation)) {
            throw new ResourceOperationException('operation.completion_unproven', 'Served web roots need the exact proven result.', 409);
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

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('operation.execution_forbidden', 'The Route parent does not own this production web root.', 422);
    }
}
