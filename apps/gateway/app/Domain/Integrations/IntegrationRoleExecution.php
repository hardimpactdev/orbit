<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Metrics\MetricsFleetExecution;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Roles consume the Node parent's retained intent without taking over its finalization. */
final readonly class IntegrationRoleExecution implements OperationFamilyExecution, OperationFamilyChildren
{
    public function __construct(private IntegrationExecution $pipeline, private MetricsFleetExecution $metrics) {}
    public function context(Operation $operation): array
    {
        $prepare = ['key' => 'role-prepare', 'step' => 'role-prepare', 'transport' => 'gateway', 'name' => 'integration.prepare-role', 'input' => [], 'resumable' => true, 'required' => true];
        return ['descriptors' => [$prepare, ...($this->prepared($operation) ? $this->delegate($operation)->context($this->planned($operation))['descriptors'] : [])]];
    }
    public function childContext(Operation $operation): array
    {
        return $this->prepared($operation) ? $this->delegate($operation)->childContext($this->planned($operation)) : ['intent' => [], 'children' => []];
    }
    public function input(Operation $operation, array $run): array
    {
        return $run['key'] === 'role-prepare' ? [] : $this->delegate($operation)->input($this->planned($operation), $run);
    }
    public function beforeRun(Operation $operation, array $run): void
    {
        if ($run['key'] === 'role-prepare') { return; }
        if (! $this->prepared($operation)) { throw new ResourceOperationException('operation.completion_unproven', 'The role intent has no proven preparation.', 409); }
        $this->delegate($operation)->beforeRun($this->planned($operation), $run);
    }
    public function received(Operation $operation, array $run, array $receipt): array
    {
        if (isset($receipt['error_code']) || $run['name'] === 'integration.prepare-role') { return $receipt; }
        if ($run['name'] === 'websocket.converge-runtime' && ! is_string($receipt['release'] ?? null)) { return ['error_code' => 'websocket.runtime_convergence_failed']; }
        if (in_array($run['name'], ['websocket.converge-runtime', 'websocket.remove-runtime', 'role.install-prerequisites'], true)) { return ['applied' => true]; }
        if ($run['name'] === 'caddy.check-addresses') { return ['verified' => true]; }
        if ($run['name'] === 'websocket.runtime-active' && ($receipt['active'] ?? false) !== true) { return ['error_code' => 'websocket.runtime_inactive']; }
        return $this->delegate($operation)->received($this->planned($operation), $run, $receipt);
    }
    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Succeeded && ! $this->prepared($operation)) { throw new ResourceOperationException('operation.completion_unproven', 'The role intent is unprepared.', 409); }
        if ($this->prepared($operation)) { $this->delegate($operation)->assertCompletion($this->planned($operation), $outcome); }
    }
    public function cancellationBoundary(Operation $operation): ?array { return null; }
    private function prepared(Operation $operation): bool
    {
        $run = ValidatedData::object($operation->state['executor_runs'] ?? [])['role-prepare'] ?? null;
        return is_array($run) && ($run['transport'] ?? null) === 'gateway' && ($run['name'] ?? null) === 'integration.prepare-role' && ($run['result'] ?? null) === ['prepared' => true];
    }
    private function planned(Operation $operation): Operation
    {
        $planned = clone $operation;
        $planned->input = IntegrationIntent::data($operation);
        return $planned;
    }
    private function delegate(Operation $operation): IntegrationExecution|MetricsFleetExecution
    {
        return str_starts_with($operation->kind, 'metrics.') ? $this->metrics : $this->pipeline;
    }
}
