<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Support\ValidatedData;

/** Reads the family's server-recorded member set without coercing IDs or changing its order. */
final class InstanceLifecycleInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    public static function members(array $input): array
    {
        $members = $input['members'] ?? null;
        if (! is_array($members) || ! array_is_list($members)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The lifecycle intent requires its recorded member set.', 422);
        }

        return array_map(ValidatedData::object(...), $members);
    }
}
