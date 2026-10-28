<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

final class StepExecutorRequest extends ExecutorRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'worker' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/D'],
            'key' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:100', 'regex:/\A[a-z][a-z0-9_.-]*\z/D'],
            'status' => ['required', 'in:running,succeeded,failed'],
            'output' => ['sometimes', 'string', 'max:8192'],
            'result' => ['sometimes', 'array'],
            'error_code' => ['required_if:status,failed', 'nullable', 'string', 'max:100', 'regex:/\A[a-z0-9_.-]+\z/D'],
        ];
    }
}
