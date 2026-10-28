<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

final class CompleteExecutorRequest extends ExecutorRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'worker' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/D'],
            'status' => ['required', 'in:succeeded,failed,cancelled'],
            'result' => ['sometimes', 'array'],
            'error_code' => ['required_if:status,failed', 'nullable', 'string', 'max:100'],
        ];
    }
}
