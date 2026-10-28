<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

final class ReadChildrenExecutorRequest extends ExecutorRequest
{
    /** @return array<string,list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
