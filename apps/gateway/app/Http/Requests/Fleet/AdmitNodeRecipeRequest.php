<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use App\Http\Requests\Operations\ExecutorRequest;
use Closure;

final class AdmitNodeRecipeRequest extends ExecutorRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['automatic' => ['required', static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== true) {
                $fail('HTTP recipe admission requires automatic to be true.');
            }
        }], 'recipe' => ['sometimes', 'string'], 'build_id' => ['nullable', 'integer', 'min:1']];
    }
}
