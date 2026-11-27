<?php

declare(strict_types=1);

namespace App\Http\Requests\Tools;

use Illuminate\Foundation\Http\FormRequest;

final class ShowToolPolicyRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['export' => ['nullable', 'string', 'in:snapshot,catalog']];
    }
}
