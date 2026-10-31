<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DockerMigrationRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['mode' => ['required', Rule::in(['plan', 'migrate', 'restore-policies'])],
            'confirm' => ['exclude_if:mode,plan', 'required', 'accepted'], 'recipe' => ['sometimes', 'string'],
            'fallback' => ['sometimes', 'boolean', 'prohibited_if:mode,restore-policies']];
    }
}
