<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/** The chart step a failed native run stopped at, from the shared terminal reader's failure. */
final class NativeFailedStep
{
    /**
     * @param  array<string,mixed>  $data  The terminal data the shared reader passes to a projection.
     * @param  list<string>  $labels  The kind's chart step names.
     */
    public static function label(array $data, array $labels): ?string
    {
        $failed = is_array($data['failure'] ?? null) && is_string($data['failure']['failed_step'] ?? null) ? $data['failure']['failed_step'] : null;
        foreach ($labels as $label) {
            // A forEach iteration is named after its step, such as "Stage the new targets-1".
            if ($failed !== null && ($failed === $label || str_starts_with($failed, $label.'-'))) {
                return $label;
            }
        }

        return null;
    }
}
