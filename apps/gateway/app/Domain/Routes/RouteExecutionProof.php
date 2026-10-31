<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;
use App\Support\ValidatedData;

/** Validates stored public Route object lists for native intent. */
final readonly class RouteExecutionProof
{
    /** @return list<array<string, mixed>> */
    public static function objects(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new ResourceOperationException('route.plan_invalid', 'A stored Route plan requires a list.', 409);
        }

        return array_map(ValidatedData::object(...), $value);
    }
}
