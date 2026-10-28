<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

final class ClaimExecutorRequest extends ExecutorRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'worker' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/D'],
            'kind' => ['required', 'string', 'max:100'],
            'operation_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
