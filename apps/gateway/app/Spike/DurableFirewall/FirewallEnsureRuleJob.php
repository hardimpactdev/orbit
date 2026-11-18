<?php

declare(strict_types=1);

namespace App\Spike\DurableFirewall;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * SPIKE: firewall:allow as one order. The job sends the intended rule as the desired-state primitive `ufw.ensure_rule`;
 * the executor reads its own table, places the rule, applies, checks and answers `applied {changes}` or `failed {code}`.
 * The WebSocket carries only the instruction and the verdict. A lost job comes back after 15 s and sends the same
 * order again; the executor answers the stored verdict marked `repeat`.
 */
final class FirewallEnsureRuleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @param array{ssh: array, rule: array, ensure: string} $intent */
    public function __construct(public string $operationKey, public array $intent, public int $nodeId, public int $slow = 0)
    {
        $this->onConnection('spike-ops');
        $this->onQueue('spike-ops');
    }

    public function handle(): void
    {
        if ($this->slow > 0 && $this->attempts() === 1) {
            sleep($this->slow); // Spike only: a window for the crash test before the order goes out.
        }
        $rule = $this->intent['rule'];
        $input = ['action' => $rule['action'], 'port' => (string) $rule['port'], 'protocol' => $rule['protocol'],
            'source' => $rule['source'], 'comment' => $rule['comment'], 'ensure' => $this->intent['ensure'],
            'retain' => (bool) ($rule['retain'] ?? false)];
        try {
            $verdict = PrimitiveClient::call("{$this->operationKey}:rule", 'rule', $this->nodeId, 'ufw.ensure_rule', $input);
        } catch (RuntimeException $exception) {
            $this->report(['ok' => false, 'error_code' => $exception->getMessage(), 'attempt' => $this->attempts()]);

            return;
        }
        $this->report(['ok' => true, 'changes' => $verdict['changes'] ?? null, 'attempt' => $this->attempts(),
            'order' => $verdict['result_ms'].($verdict['repeat'] ? ' repeat' : '')]);
    }

    public function failed(Throwable $exception): void
    {
        $this->report(['ok' => false, 'error_code' => 'operation.failed', 'message' => $exception->getMessage()]);
    }

    private function report(array $outcome): void
    {
        Redis::connection('gateway')->command('rpush', ["spike:job:{$this->operationKey}", json_encode($outcome, JSON_THROW_ON_ERROR)]);
        Redis::connection('gateway')->command('expire', ["spike:job:{$this->operationKey}", 600]);
    }
}
