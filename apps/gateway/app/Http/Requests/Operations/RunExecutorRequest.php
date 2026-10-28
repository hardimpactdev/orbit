<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Domain\Shared\ResourceOperationException;
use Illuminate\Contracts\Validation\Validator;

final class RunExecutorRequest extends ExecutorRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'worker' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/D'],
            'key' => ['required', 'string', 'max:128'],
            'node_id' => ['prohibited'],
            'transport' => ['required', 'in:agent,remote_program'],
            'name' => ['required', 'string', 'max:100', 'regex:/\A[a-z][a-z0-9_-]*\.[a-z][a-z0-9_-]*\z/D'],
            'input' => ['present', 'array'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'The invocation does not match the Operation contract.', 422);
    }
}
