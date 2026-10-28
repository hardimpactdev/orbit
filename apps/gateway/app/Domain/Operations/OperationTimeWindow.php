<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use DateTimeZone;
use Illuminate\Support\Carbon;

final readonly class OperationTimeWindow implements OperationRunConstraint
{
    public function name(): string
    {
        return 'time_window';
    }

    /** @param array<string,mixed> $constraint */
    public function validate(array $constraint): void
    {
        $keys = array_keys($constraint);
        sort($keys);
        if ($keys !== ['end', 'start', 'timezone'] || ! is_string($constraint['timezone'])
            || ! in_array($constraint['timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
            || ! is_string($constraint['start']) || ! is_string($constraint['end'])
            || preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $constraint['start']) !== 1
            || preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $constraint['end']) !== 1
            || $constraint['start'] === $constraint['end']) {
            throw new ResourceOperationException('operation.run_constraint_failed', 'The run constraint is invalid.', 422);
        }
    }

    public function assertAllowed(Operation $operation, array $constraint): void
    {
        $this->validate($constraint);
        $now = Carbon::now(new DateTimeZone((string) $constraint['timezone']))->format('H:i');
        $allowed = $constraint['start'] < $constraint['end']
            ? $now >= $constraint['start'] && $now < $constraint['end']
            : $now >= $constraint['start'] || $now < $constraint['end'];
        if (! $allowed) {
            throw new ResourceOperationException('operation.run_constraint_failed', 'The run is outside its admitted time window.', 409);
        }
    }
}
