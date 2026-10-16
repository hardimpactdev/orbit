<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Clusters\ClusterTld;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A Cluster TLD that {@see ClusterTld::isValid()} accepts. */
final readonly class ValidClusterTld implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! ClusterTld::isValid($value)) {
            $fail("The {$attribute} field must be one DNS label that covers no private names.");
        }
    }
}
