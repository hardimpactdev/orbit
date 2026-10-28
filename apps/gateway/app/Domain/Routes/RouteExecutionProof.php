<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Family ordering checks use only Gateway-recorded transport receipts.
 * @phpstan-import-type Descriptor from OperationFamilyExecution
 */
final readonly class RouteExecutionProof
{
    /** @param list<Descriptor> $descriptors */
    public static function preceding(Operation $operation, array $descriptors, string $key): void
    {
        foreach ($descriptors as $descriptor) {
            if ($descriptor['key'] === $key) {
                return;
            }
            $run = data_get($operation->state, 'executor_runs.'.$descriptor['key']);
            if (! is_array($run) || ($run['name'] ?? null) !== $descriptor['name'] || ($run['transport'] ?? null) !== $descriptor['transport']
                || ! is_array($run['result'] ?? null) || isset($run['result']['error_code'])) {
                throw new ResourceOperationException('operation.completion_unproven', 'The preceding Route effect needs a successful receipt.', 409);
            }
        }
        throw new ResourceOperationException('operation.execution_forbidden', 'This Route step was not admitted.', 422);
    }

    /** @return array<string, mixed> */
    public static function result(Operation $operation, string $key): array
    {
        return ValidatedData::object(data_get($operation->state, 'executor_runs.'.$key.'.result') ?? []);
    }

    /** @return list<array<string, mixed>> */
    public static function objects(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new ResourceOperationException('route.plan_invalid', 'A stored Route plan requires a list.', 409);
        }

        return array_map(ValidatedData::object(...), $value);
    }
}
