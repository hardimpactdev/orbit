<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Gateway\GatewayQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * One run of a scheduled Artisan command on the Gateway queue (docs/reference/gateway-queue.md#scheduled-work). It runs
 * the command in its own PHP process, as the scheduler did, so the command keeps no state from an earlier run. It is
 * unique per command: while one waits or runs, the scheduler dispatches no second one.
 */
final class RunScheduledCommand implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Stays below the connection's `retry_after` of 1800 seconds. */
    public int $timeout;

    /**
     * @param  string  $command  the command name, without arguments
     * @param  int  $uniqueFor  seconds after which a lock that a lost worker held frees itself
     */
    public function __construct(public string $command, public int $uniqueFor, GatewayQueue $queue = GatewayQueue::Tasks)
    {
        $this->timeout = min(1700, $uniqueFor);
        $this->onConnection(GatewayQueue::Connection)->onQueue($queue->value);
    }

    public function uniqueId(): string
    {
        return $this->command;
    }

    public function handle(): void
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), $this->command, '--no-interaction'], base_path(), timeout: $this->timeout - 10);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::error('A scheduled command on the Gateway queue failed.', [
                'command' => $this->command,
                'exit_code' => $process->getExitCode(),
                'output' => mb_substr(trim($process->getErrorOutput().$process->getOutput()), -2000),
            ]);
        }
    }
}
