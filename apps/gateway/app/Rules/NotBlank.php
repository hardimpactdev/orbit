<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses an empty or whitespace-only string. Implicit, because Laravel skips its other rules for an empty string, which would store
 * it. Null passes, so a nullable field can still be cleared.
 */
final class NotBlank implements ValidationRule
{
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && trim($value) === '') {
            $fail("The {$attribute} field must not be blank; send null to clear it.");
        }
    }
}
