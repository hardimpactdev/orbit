<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Family ordering checks use only Gateway-recorded transport receipts. */
final readonly class RouteExecutionProof
{
    /** @param list<array<string, mixed>> $descriptors */
    public static function preceding(Operation $operation, array $descriptors, string $key): void
    {
        foreach ($descriptors as $descriptor) {
            if ($descriptor['key'] === $key) {
                return;
            }
            $run = $operation->state['executor_runs'][$descriptor['key']] ?? null;
            if (! is_array($run) || ($run['name'] ?? null) !== $descriptor['name'] || ($run['transport'] ?? null) !== $descriptor['transport']
                || ! is_array($run['result'] ?? null) || isset($run['result']['error_code'])) {
                throw new ResourceOperationException('operation.completion_unproven', 'The preceding Route effect needs a successful receipt.', 409);
            }
        }
        throw new ResourceOperationException('operation.execution_forbidden', 'This Route step was not admitted.', 422);
    }
}
