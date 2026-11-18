<?php

declare(strict_types=1);

namespace App\Spike\DurableFirewall;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * SPIKE: firewall:allow as one plain Horizon job on idempotent Executor primitives, with no workflow engine.
 *
 * read → plan (PHP) → one ufw.ensure per change → check, all inside one job. Durability comes from the primitives: every
 * step keys its Operation on `$operationKey`, so when a lost job comes back (the queue's 15 s retry_after) it starts
 * over and each step it already finished returns the stored result marked `repeat` in milliseconds.
 */
final class FirewallAllowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @param array{ssh: array, rule: array, ensure: string} $intent */
    public function __construct(public string $operationKey, public array $intent, public int $nodeId, public int $slow = 0)
    {
        $this->onConnection('spike-ops');
        $this->onQueue('spike-ops');
    }

    public function handle(): void
    {
        $this->report($this->converge());
    }

    public function failed(Throwable $exception): void
    {
        $this->report(['ok' => false, 'error_code' => 'operation.failed', 'message' => $exception->getMessage()]);
    }

    private function converge(): array
    {
        $read = PrimitiveClient::call("{$this->operationKey}:read-1", 'read-1', $this->nodeId, 'ufw.read');
        $timings = ['attempt' => $this->attempts(), 'read' => $read['result_ms'].($read['repeat'] ? ' repeat' : '')];
        try {
            $changes = UfwPlan::changes($this->intent, $read['stdout']);
        } catch (UfwRefusal $refusal) {
            return ['ok' => false, 'error_code' => $refusal->getMessage(), 'failed_step' => 'plan', 'timings' => $timings];
        }
        $final = $read;
        foreach ($changes as $index => $change) {
            if ($this->slow > 0 && $index === 0 && $this->attempts() === 1) {
                sleep($this->slow); // Spike only: a window for the crash test between the read and the first change.
            }
            $final = PrimitiveClient::call("{$this->operationKey}:ensure-{$index}", "ensure-{$index}", $this->nodeId, 'ufw.ensure', $change);
            $timings["ensure-{$index}"] = $final['result_ms'].($final['repeat'] ? ' repeat' : '');
        }
        if ($changes !== []) {
            $final = PrimitiveClient::call("{$this->operationKey}:read-2", 'read-2', $this->nodeId, 'ufw.read');
            $timings['read-2'] = $final['result_ms'].($final['repeat'] ? ' repeat' : '');
        }
        try {
            $result = UfwPlan::check($this->intent, $final['stdout'], count($changes));
        } catch (UfwRefusal $refusal) {
            return ['ok' => false, 'error_code' => $refusal->getMessage(), 'failed_step' => 'check', 'timings' => $timings];
        }

        return ['ok' => true, 'result' => $result, 'changes' => count($changes), 'timings' => $timings];
    }

    private function report(array $outcome): void
    {
        Redis::connection('gateway')->command('rpush', ["spike:job:{$this->operationKey}", json_encode($outcome, JSON_THROW_ON_ERROR)]);
        Redis::connection('gateway')->command('expire', ["spike:job:{$this->operationKey}", 600]);
    }
}
