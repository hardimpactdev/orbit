<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Models\Operation;

/** A fixed agent transport for shared executor tests, independent of converted product kinds. */
final readonly class PolledOperationFixture implements OperationCompletion
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

    public function complete(Operation $operation, OperationOutcome $outcome): void {}
}
