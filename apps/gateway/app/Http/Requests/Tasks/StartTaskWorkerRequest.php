<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\TaskWorkerData;

final class StartTaskWorkerRequest extends StartTaskProvisionRequest
{
    protected function workerPresence(): string
    {
        return 'required';
    }

    public function worker(): TaskWorkerData
    {
        return TaskWorkerData::from($this->validated('worker'));
    }
}
