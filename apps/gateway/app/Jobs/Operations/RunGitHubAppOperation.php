<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Actions\GitHub\RunGitHubAppOperationAction;
use App\Domain\Gateway\GatewayQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Contains only a public Operation ID; credentials are read inside the Gateway. */
final class RunGitHubAppOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public int $operationId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(RunGitHubAppOperationAction $action): void
    {
        $action->handle($this->operationId);
    }

    public function failed(): void
    {
        app(RunGitHubAppOperationAction::class)->fail($this->operationId);
    }
}
