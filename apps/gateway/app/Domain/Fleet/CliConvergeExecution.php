<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Arr;

final readonly class CliConvergeExecution implements OperationFamilyExecution
{
    public function __construct(private CliConvergePlan $plan, private CliConvergeResult $result) {}

    public function context(Operation $operation): array
    {
        $descriptors = [];
        if (($operation->input['skip_reason'] ?? null) === null && ($operation->input['plan_error'] ?? null) === null) {
            $descriptors[] = ['key' => 'cli-install', 'step' => 'cli-install', 'transport' => 'agent', 'name' => 'cli.install',
                'input' => Arr::only($operation->input, ['user', 'release', 'profile']), 'required' => true, 'resumable' => true];
        }
        $descriptors[] = ['key' => 'cli-converge', 'step' => 'cli-converge', 'transport' => 'gateway', 'name' => 'fleet.cli-converge-result',
            'input' => [], 'required' => true, 'resumable' => true];

        return ['descriptors' => $descriptors];
    }

    public function input(Operation $operation, array $run): array
    {
        $descriptor = array_find($this->context($operation)['descriptors'], static fn (array $descriptor): bool => $descriptor['key'] === $run['key']
            && $descriptor['transport'] === $run['transport'] && $descriptor['name'] === $run['name']);
        if ($descriptor === null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The CLI invocation has no admitted descriptor.', 422);
        }

        return $descriptor['input'];
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        if ($run['name'] === 'cli.install' && $this->plan->exclusion(Node::query()->findOrFail($operation->node_id)) !== null) {
            throw new ResourceOperationException('fleet.cli_ineligible', 'The Node is no longer eligible for a fleet CLI install.', 409);
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        $this->result->assertOutcome($operation, $outcome);
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }
}
