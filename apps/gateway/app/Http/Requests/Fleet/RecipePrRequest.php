<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use Illuminate\Foundation\Http\FormRequest;

final class RecipePrRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['action' => ['required', 'in:open,update,merge,retire'], 'variant' => ['required', 'in:snapshot,artifacts'],
            'pull_number' => ['required', 'integer', 'min:0'], 'head_sha' => ['present', 'nullable', 'string'], 'date' => ['required', 'regex:/\A[0-9]{8}\z/D']];
    }
}
