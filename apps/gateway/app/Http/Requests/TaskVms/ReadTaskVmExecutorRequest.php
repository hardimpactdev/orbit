<?php

declare(strict_types=1);

namespace App\Http\Requests\TaskVms;

use App\Http\Requests\Operations\ExecutorRequest;

final class ReadTaskVmExecutorRequest extends ExecutorRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}
