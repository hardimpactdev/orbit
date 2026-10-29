<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Family phase binding and state proof. The shared kit owns child execution and waiting. */
abstract readonly class RoutePhaseExecution implements OperationFamilyChildren, OperationFamilyExecution
{
    abstract protected function command(): string;

    /** @return list<string> */
    abstract public function sequence(Operation $operation): array;

    public function context(Operation $operation): array
    {
        $commands = [['parent-prepare', ['action' => 'prepare']]];
        $plan = $operation->state['route_plan'] ?? null;
        if (is_array($plan)) {
            $sequence = $this->sequence($operation);
            if ($this instanceof RouteParentExecution && ! isset($operation->state['route_branch'])) {
                $sequence = array_values(array_unique([...$sequence, 'rollback-preparations-caddy', 'rollback-preparations-certificates', 'rollback-preparations-record', 'rollback-public-edge', 'rollback-public-firewall', 'rollback-environment', 'rollback-dns',
                    'rollback-caddy', 'rollback-certificates', 'rollback-record', 'rollback-web-root-stored', 'rollback-workload-certificate',
                    'rollback-prepare-web-root', 'synchronize-web-root-urls.rollback', 'rollback-production-runtime', 'rollback-workload-prepared', 'rollback-workload-caddy', 'rollback-initial-dns', 'rollback-initial-caddy', 'rollback-initial-php', 'rollback-initial-certificates', 'rollback-initial-firewall']));
            }
            if ($this instanceof ClusterRouterParentExecution && ! isset($operation->state['route_branch'])) {
                $sequence = array_values(array_unique([...$sequence, 'restore-dns', 'await-restored-dns-expiry', 'withdraw-candidate-sites', 'remove-candidate-certificates', 'remove-candidate-firewall']));
            }
            foreach ($sequence as $stage) {
                $commands[] = ["stage-open.{$stage}", ['action' => 'open', 'stage' => $stage]];
                $commands[] = ["stage-commit.{$stage}", ['action' => 'commit', 'stage' => $stage]];
            }
            $commands[] = ['route-proof', ['action' => 'prove']];
        }
        $descriptors = [];
        foreach ($commands as [$key, $input]) {
            $descriptors[] = ['key' => $key, 'step' => str_starts_with($key, 'stage-commit.') ? str_replace('stage-commit.', 'stage.', $key) : $key,
                'transport' => 'gateway', 'name' => $this->command(), 'input' => $input, 'resumable' => true, 'required' => true];
        }

        return ['descriptors' => $descriptors, 'result' => ['route_branch' => $operation->state['route_branch'] ?? null,
            'failure_code' => $operation->state['route_failure_code'] ?? null]];
    }

    public function childContext(Operation $parent): array
    {
        $plan = ValidatedData::object($parent->state['route_plan'] ?? []);
        $stage = $parent->state['route_active_stage'] ?? null;
        $children = is_string($stage) ? RouteExecutionProof::objects(ValidatedData::object($plan['stages'] ?? [])[$stage] ?? []) : [];
        $intents = [];
        foreach ($children as $child) {
            $intents[] = ['key' => ValidatedData::string($child['key']), 'kind' => ValidatedData::string($child['kind']),
                'node_id' => ValidatedData::integer($child['node_id']), 'input' => ValidatedData::object($child['input'])];
        }

        return ['intent' => $plan, 'children' => $intents];
    }

    public function input(Operation $operation, array $run): array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === $run['key'] && $descriptor['name'] === $run['name'] && $descriptor['transport'] === $run['transport']) {
                return $descriptor['input'];
            }
        }
        throw new ResourceOperationException('operation.execution_forbidden', 'This Route transition was not admitted.', 422);
    }

    public function beforeRun(Operation $operation, array $run): void {}

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        $proof = RouteExecutionProof::result($operation, 'route-proof');
        if ($outcome->status->value === 'succeeded' && (isset($operation->state['route_branch']) || ($proof['ready'] ?? false) !== true
            || ! OperationChildIntent::same($outcome->result ?? [], $proof))) {
            throw new ResourceOperationException('operation.completion_unproven', 'The Route needs its final state proof.', 409);
        }
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        if (! Operation::query()->where('parent_operation_id', $operation->id)->where('status', 'succeeded')->exists()) {
            return null;
        }
        // A stamped successful child proves the remote phase before forward completion.
        foreach ($this->sequence($operation) as $stage) {
            if (RouteExecutionProof::result($operation, 'stage-open.'.$stage) !== []) {
                return ['key' => 'stage-open.'.$stage, 'transport' => 'gateway', 'name' => $this->command(), 'result' => ['opened' => true]];
            }
        }

        return null;
    }
}
