<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use LogicException;

final readonly class OperationRunConstraints
{
    /** @return array<string,OperationRunConstraint> */
    private function registered(): array
    {
        $checks = ['time_window' => new OperationTimeWindow];
        foreach (app()->tagged(OperationRunConstraint::class) as $check) {
            if (! $check instanceof OperationRunConstraint || isset($checks[$check->name()])) {
                throw new LogicException('Run constraints require unique named implementations.');
            }
            $checks[$check->name()] = $check;
        }

        return $checks;
    }

    /** @param array<string,array<string,mixed>> $constraints */
    public function validate(Operation $operation, array $constraints): void
    {
        $checks = $this->registered();
        foreach ($constraints as $name => $parameters) {
            if (! isset($checks[$name]) || ! is_array($parameters) || (array_is_list($parameters) && $parameters !== [])) {
                throw new ResourceOperationException('operation.run_constraint_failed', 'The run constraint is invalid.', 422);
            }
            if ($checks[$name] instanceof OperationTimeWindow) {
                $checks[$name]->validate($parameters);
            } else {
                $checks[$name]->assertAllowed($operation, $parameters);
            }
        }
    }

    public function assertAllowed(Operation $operation): void
    {
        $constraints = $operation->run_constraints ?? [];
        $this->validate($operation, $constraints);
        $checks = $this->registered();
        foreach ($constraints as $name => $parameters) {
            $checks[$name]->assertAllowed($operation, $parameters);
        }
    }
}
