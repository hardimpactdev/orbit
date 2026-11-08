<?php

declare(strict_types=1);

namespace App\Jobs\Tasks;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Tasks\TaskVmAdmission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class AdmitTaskVm implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(public int $operationId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value);
    }

    public function handle(TaskVmAdmission $admission): void
    {
        $admission->admit($this->operationId);
    }
}
