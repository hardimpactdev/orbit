<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Instances\DefaultInstanceName;
use App\Infrastructure\Instances\ContainerWorkspaceRead;
use App\Models\Instance;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Records the workspace baseline of a container Instance after its create or clone succeeded
 * (docs/reference/development-containers.md#workspace-work-state). Nothing replaces a recorded baseline, and a default
 * Instance has none. The job runs after the projection's commit, never inside it. A job that starts later than
 * LateSeconds after the create ended records nothing: by then the workspace may hold work of its own, and the read
 * falls back to the Project default instead.
 */
final class RecordWorkspaceBaseline implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public const int LateSeconds = 60;

    public int $endedAt;

    public function __construct(public int $instanceId, ?int $endedAt = null)
    {
        $this->endedAt = $endedAt ?? now()->getTimestamp();
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value)->afterCommit();
    }

    public function handle(ContainerWorkspaceRead $read): void
    {
        $instance = Instance::query()->with('node')->find($this->instanceId);
        $runtime = $instance?->runtime()->first();
        if ($instance === null || $runtime === null || $instance->name === DefaultInstanceName::Name
            || $runtime->workspace_baseline !== null || now()->getTimestamp() - $this->endedAt > self::LateSeconds) {
            return;
        }
        $baseline = $read->baseline($instance);
        if ($baseline !== null) {
            $runtime->update(['workspace_baseline' => $baseline, 'workspace_baseline_at' => now()]);
        }
    }
}
