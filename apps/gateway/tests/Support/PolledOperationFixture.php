<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Models\Operation;

/** A fixed agent transport for shared executor tests, independent of converted product kinds. */
final readonly class PolledOperationFixture implements OperationCompletion, OperationFamilyExecution
{
    public static function definition(): array
    {
        return [
            'executor' => 'swamp', 'completion' => self::class,
            'runs' => [[
                'transport' => 'agent', 'name' => 'firewall.rule', 'resumable' => true,
                'input_fields' => ['name', 'action', 'source', 'port', 'protocol'],
                'input_values' => ['operation' => 'converge'], 'node_field' => 'node_id',
            ]],
            'completion_contract' => ['step' => 'effect', 'transport' => 'agent', 'name' => 'firewall.rule', 'result' => ['backend' => 'active']],
            'result_fields' => ['backend', 'error_code'],
        ];
    }

    public function context(Operation $operation): array
    {
        return ['descriptors' => []];
    }

    public function input(Operation $operation, array $run): array
    {
        return [];
    }

    public function beforeRun(Operation $operation, array $run): void {}

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void {}
}
