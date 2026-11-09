<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;

final class ConvergeNodeFootprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'base' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'required', 'array', 'list', 'min:1', 'prohibited_if:base,true'],
            'roles.*' => ['required', 'string', 'distinct'],
            'force' => ['prohibited'],
            'full' => ['prohibited'],
            'worker_credentials' => ['prohibited'],
        ];
    }

    /** @return list<string>|null */
    public function roles(): ?array
    {
        return $this->exists('roles') ? ValidatedData::stringList($this->validated('roles')) : null;
    }
}
