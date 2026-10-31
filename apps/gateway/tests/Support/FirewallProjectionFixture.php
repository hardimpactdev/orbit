<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Firewall\FirewallIntentProjection;
use App\Models\Operation;

/** Project a native outcome onto admitted Firewall intent without a Gateway executor. */
final readonly class FirewallProjectionFixture
{
    public static function finish(Operation $operation): void
    {
        $operation->refresh();
        app(FirewallIntentProjection::class)->apply($operation, [
            'run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded',
            'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
            'result' => ['backend' => $operation->kind === 'firewall.remove' ? 'absent' : 'active'],
        ]);
    }
}
