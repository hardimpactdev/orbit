<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Nodes\NodeTld;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A Node TLD that {@see NodeTld::isValid()} accepts. */
final readonly class ValidNodeTld implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! NodeTld::isValid($value)) {
            $fail("The {$attribute} field must be DNS labels that cover no private names.");
        }
    }
}
