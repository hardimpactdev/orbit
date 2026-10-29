<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use App\Http\Requests\Operations\ExecutorRequest;

final class RecipeFleetStateRequest extends ExecutorRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['build_id' => ['required', 'integer', 'min:1'], 'state' => ['required', 'in:complete,interrupted']];
    }
}
