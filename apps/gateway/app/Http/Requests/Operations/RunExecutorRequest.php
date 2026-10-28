<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

final class RunExecutorRequest extends ExecutorRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'worker' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/D'],
            'key' => ['required', 'string', 'max:128'],
            'node_id' => ['required', 'integer', 'min:1'],
            'transport' => ['required', 'in:agent,remote_program'],
            'name' => ['required', 'string', 'max:100', 'regex:/\A[a-z][a-z0-9_-]*\.[a-z][a-z0-9_-]*\z/D'],
            'input' => ['present', 'array'],
        ];
    }
}
