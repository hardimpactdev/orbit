<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

final class ChildrenExecutorRequest extends ExecutorRequest
{
    /** @return array<string,list<mixed>> */
    public function rules(): array
    {
        return [
            'worker' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/D'],
            'key' => ['required', 'string', 'max:128', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]*\z/D'],
            'kind' => ['sometimes', 'string', 'max:100'],
            'node_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'input' => ['sometimes', 'array'],
        ];
    }
}
