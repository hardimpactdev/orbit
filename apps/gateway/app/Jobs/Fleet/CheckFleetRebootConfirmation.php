<?php

declare(strict_types=1);

namespace App\Jobs\Fleet;

use App\Domain\Fleet\FleetReboot;
use App\Domain\Fleet\FleetRebootAlerts;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\NodeFacts\NodeFactKind;
use App\Models\NodeFact;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Confirms the reboot that Node 209 scheduled for itself at the end of a pass (ADR 0253). Its run cannot wait across its
 * own reboot, so the Gateway reads the boot from the Node's `metrics` fact: the agent reports its uptime, and a boot after
 * the pass ended confirms it. Thirty minutes after the pass ended without one, it raises `reboot_unconfirmed` once.
 * The next pass checks the Node and records the result.
 */
final class CheckFleetRebootConfirmation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int GraceMinutes = 30;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(FleetRebootAlerts $alerts): void
    {
        $now = CarbonImmutable::now();
        $passes = Operation::query()->where('kind', FleetReboot::Kind)->whereNotNull('finished_at')
            ->whereBetween('finished_at', [$now->subDay(), $now->subMinutes(self::GraceMinutes)])->get();
        foreach ($passes as $pass) {
            foreach (is_array($pass->result['nodes'] ?? null) ? $pass->result['nodes'] : [] as $node) {
                if (! is_array($node) || ($node['outcome'] ?? null) !== 'scheduled' || ! is_int($node['node_id'] ?? null)) {
                    continue;
                }
                if (! self::bootedSince($node['node_id'], CarbonImmutable::parse($pass->finished_at))) {
                    $alerts->unconfirmed($pass, $node['node_id'], is_string($node['name'] ?? null) ? $node['name'] : (string) $node['node_id']);
                }
            }
        }
    }

    /** Whether the Node's agent reported a boot after this time: its observation minus its uptime. */
    public static function bootedSince(int $nodeId, CarbonImmutable $after): bool
    {
        $fact = NodeFact::query()->where('node_id', $nodeId)->where('kind', NodeFactKind::Metrics)->first();
        $uptime = $fact?->data['uptime_seconds'] ?? null;

        return $fact instanceof NodeFact && is_int($uptime)
            && CarbonImmutable::parse($fact->observed_at)->subSeconds($uptime)->greaterThan($after);
    }
}
