<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeAccess;
use App\Models\Operation;
use App\Support\ValidatedData;

/** After deleting the grant, stream revocation must run even if cancellation arrives. */
final readonly class NodeAccessOperationExecution implements OperationFamilyExecution
{
    public function context(Operation $operation): array
    {
        return ['descriptors' => [
            ['key' => 'access-remove', 'step' => 'access-remove', 'transport' => 'gateway', 'name' => 'node.remove-access-edge',
                'input' => [], 'required' => true, 'resumable' => true],
            ['key' => 'access-stream-revoke', 'step' => 'access-stream-revoke', 'transport' => 'agent', 'name' => 'caddy.reload',
                'input' => [], 'required' => true, 'resumable' => false],
        ]];
    }

    public function input(Operation $operation, array $run): array
    {
        return [];
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        if ($run['name'] === 'caddy.reload' && ! $this->removed($operation)) {
            throw $this->unproven();
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if ($run['key'] === 'access-remove' && $run['name'] === 'node.remove-access-edge'
            && ($receipt['access_removed'] ?? null) === true && ! isset($receipt['error_code'])) {
            NodeAccess::query()->where('consumer_node_id', $operation->input['consumer_node_id'])
                ->where('serving_node_id', $operation->node_id)->delete();
        }

        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if (($outcome->status === OperationStatus::Succeeded && ! $this->removed($operation))
            || ($outcome->status === OperationStatus::Cancelled && $this->removed($operation))) {
            throw $this->unproven();
        }
    }

    public function cancellationBoundary(Operation $operation): array
    {
        return ['key' => 'access-remove', 'transport' => 'gateway', 'name' => 'node.remove-access-edge', 'result' => ['access_removed' => true]];
    }

    private function removed(Operation $operation): bool
    {
        $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
        $run = $runs['access-remove'] ?? null;

        return is_array($run) && ($run['transport'] ?? null) === 'gateway' && ($run['name'] ?? null) === 'node.remove-access-edge'
            && is_array($run['result'] ?? null) && ($run['result']['access_removed'] ?? null) === true && ! isset($run['result']['error_code']);
    }

    private function unproven(): ResourceOperationException
    {
        return new ResourceOperationException('operation.completion_unproven', 'Access stream revocation requires the recorded edge removal.', 409);
    }
}
