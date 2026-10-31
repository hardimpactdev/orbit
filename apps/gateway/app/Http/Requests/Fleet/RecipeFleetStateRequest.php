<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use Illuminate\Foundation\Http\FormRequest;

final class RecipeFleetStateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['build_id' => ['required', 'integer', 'min:1'], 'state' => ['required', 'in:complete,interrupted']];
    }
}
