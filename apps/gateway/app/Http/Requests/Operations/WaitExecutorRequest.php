<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

final class WaitExecutorRequest extends ExecutorRequest
{
    /** @return array<string,list<mixed>> */
    public function rules(): array
    {
        return [
            'worker' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/D'],
            'keys' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'keys.*' => ['required', 'string', 'max:128', 'distinct:strict'],
        ];
    }
}
