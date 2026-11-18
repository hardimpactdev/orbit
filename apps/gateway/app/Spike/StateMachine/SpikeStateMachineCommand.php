<?php

declare(strict_types=1);

namespace App\Spike\StateMachine;

use App\Models\Operation;
use Illuminate\Console\Command;

/**
 * SPIKE: drives and inspects the Gateway state machine.
 *
 *  spike:sm start   — starts N fan-out operations: wait-long (app-dev) ∥ rule-a (app-dev) ∥ rule-b (app-prod) → rule-c
 *  spike:sm tick    — the only periodic part: re-send overdue steps (runs in a loop with --loop)
 *  spike:sm show    — prints an Operation's timeline
 */
final class SpikeStateMachineCommand extends Command
{
    protected $signature = 'spike:sm {mode : start|tick|show|flag|last|faults} {id? : Operation id for show}
        {--count=1} {--port=19000} {--long=20} {--drop=* : step whose first receipt is lost} {--wait} {--loop}';

    protected $description = 'SPIKE: the Gateway as a state machine over executor instructions';

    public function handle(): int
    {
        return match ($this->argument('mode')) {
            'start' => $this->start(),
            'tick' => $this->tick(),
            'show' => $this->show((int) $this->argument('id')),
            'flag' => $this->flag((string) $this->argument('id')),
            'faults' => $this->faults((string) $this->argument('id')),
            'last' => $this->show((int) Operation::query()->where('kind', Engine::Kind)->latest('id')->value('id')),
            default => self::INVALID,
        };
    }

    private function start(): int
    {
        $ids = [];
        $t0 = Engine::nowMs();
        for ($i = 0; $i < (int) $this->option('count'); $i++) {
            $port = (int) $this->option('port') + $i * 3;
            $rule = fn (int $p): array => ['action' => 'allow', 'port' => (string) $p, 'protocol' => 'tcp', 'source' => 'any',
                'comment' => 'orbit sm-spike', 'ensure' => 'present', 'retain' => false];
            $steps = [
                'wait-long' => ['node' => 3, 'action' => 'spike.wait', 'input' => ['seconds' => (int) $this->option('long')], 'after' => [], 'budget_s' => 600],
                'rule-a' => ['node' => 3, 'action' => 'ufw.ensure_rule', 'input' => $rule($port), 'after' => [], 'budget_s' => 120],
                'rule-b' => ['node' => 4, 'action' => 'ufw.ensure_rule', 'input' => $rule($port + 1), 'after' => [], 'budget_s' => 120],
                'rule-c' => ['node' => 3, 'action' => 'ufw.ensure_rule', 'input' => $rule($port + 2), 'after' => ['wait-long', 'rule-a', 'rule-b'], 'budget_s' => 120],
            ];
            $ids[] = Engine::start($steps, ['drop_receipt' => $this->option('drop')])->id;
        }
        $this->line('started operations '.implode(',', $ids).' in '.(Engine::nowMs() - $t0).' ms (no job, no worker held)');
        if (! $this->option('wait')) {
            return self::SUCCESS;
        }
        while (Operation::query()->whereIn('id', $ids)->where('status', 'running')->exists()) {
            usleep(200_000);
        }
        foreach ($ids as $id) {
            $this->show($id);
        }

        return self::SUCCESS;
    }

    /** `spike:sm flag on|off`: route.create runs on the state machine (on) or on Swamp (off). */
    private function flag(string $value): int
    {
        \Illuminate\Support\Facades\Cache::forever('spike:route-engine', $value === 'on');
        $this->line('spike:route-engine = '.var_export(\Illuminate\Support\Facades\Cache::get('spike:route-engine'), true));

        return self::SUCCESS;
    }

    /** `spike:sm faults caddy-3,records-1` drops the first receipt of those steps in new Route runs; `none` clears it. */
    private function faults(string $steps): int
    {
        \Illuminate\Support\Facades\Cache::forever('spike:route-faults', $steps === 'none' ? [] : ['drop_receipt' => explode(',', $steps)]);
        $this->line('spike:route-faults = '.json_encode(\Illuminate\Support\Facades\Cache::get('spike:route-faults')));

        return self::SUCCESS;
    }

    private function tick(): int
    {
        do {
            $resent = Engine::tick();
            if ($resent > 0) {
                $this->line(date('H:i:s')." re-sent {$resent} step(s)");
            }
            usleep(1_000_000);
        } while ($this->option('loop'));

        return self::SUCCESS;
    }

    private function show(int $id): int
    {
        $operation = Operation::query()->findOrFail($id);
        $state = $operation->state;
        $t0 = $state['started_ms'];
        $this->line(sprintf('operation %d %s in %s ms', $id, $operation->status->value,
            isset($state['finished_ms']) ? $state['finished_ms'] - $t0 : '…'));
        foreach ($state['steps'] as $name => $step) {
            $sends = implode(',', array_map(fn ($ms) => '+'.($ms - $t0), $step['sends']));
            $this->line(sprintf('  %-10s %-7s sent [%s] done %s%s%s%s', $name, $step['status'], $sends,
                isset($step['done_ms']) ? '+'.($step['done_ms'] - $t0) : '-',
                ($step['repeat'] ?? false) ? ' repeat' : '',
                isset($step['dropped']) ? ' dropped='.$step['dropped'] : '',
                isset($step['error']) ? ' error='.$step['error'] : ''));
        }

        return self::SUCCESS;
    }
}
