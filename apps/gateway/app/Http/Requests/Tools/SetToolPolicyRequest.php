<?php

declare(strict_types=1);

namespace App\Http\Requests\Tools;

use Illuminate\Foundation\Http\FormRequest;

final class SetToolPolicyRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['mode' => ['required', 'string', 'in:database,file'], 'catalog_path' => ['nullable', 'string', 'max:4096', 'starts_with:/']];
    }
}
