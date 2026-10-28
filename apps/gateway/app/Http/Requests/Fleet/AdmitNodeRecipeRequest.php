<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use App\Http\Requests\Operations\ExecutorRequest;

final class AdmitNodeRecipeRequest extends ExecutorRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['automatic' => ['sometimes', 'boolean'], 'recipe' => ['sometimes', 'string'], 'build_id' => ['nullable', 'integer', 'min:1']];
    }
}
