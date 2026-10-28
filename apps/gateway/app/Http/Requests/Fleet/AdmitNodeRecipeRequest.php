<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use Illuminate\Foundation\Http\FormRequest;

final class AdmitNodeRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['automatic' => ['sometimes', 'boolean'], 'recipe' => ['sometimes', 'string'], 'build_id' => ['nullable', 'integer', 'min:1']];
    }
}
