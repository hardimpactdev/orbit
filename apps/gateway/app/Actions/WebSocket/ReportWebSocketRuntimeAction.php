<?php

declare(strict_types=1);

namespace App\Actions\WebSocket;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketRegistry;
use App\Models\WebSocketRuntime;
use Illuminate\Support\Facades\DB;

final readonly class ReportWebSocketRuntimeAction
{
    public function __construct(private WebSocketRegistry $registry) {}

    public function register(WebSocketRuntime $runtime, string $bootId): void
    {
        $this->registry->write(function () use ($runtime, $bootId): void {
            $runtime->refresh();
            if ($runtime->boot_id === $bootId) {
                return;
            }
            if (DB::table('websocket_runtime_boots')->where('runtime_id', $runtime->id)->where('boot_id', $bootId)->exists()) {
                throw new ResourceOperationException('websocket.boot_superseded', 'This process boot has been superseded.', 409);
            }
            DB::table('websocket_runtime_boots')->insert(['runtime_id' => $runtime->id, 'boot_id' => $bootId]);
            $runtime->update(['boot_id' => $bootId, 'epoch' => null, 'applied_revision' => null, 'report_sequence' => 0, 'reported_at' => null, 'state' => 'starting', 'error' => null]);
        });
    }

    /** @param array<string, mixed> $report */
    public function execute(WebSocketRuntime $runtime, array $report): void
    {
        $this->registry->write(function () use ($runtime, $report): void {
            $runtime->refresh();
            $version = $this->registry->version();
            if ($runtime->boot_id !== $report['boot_id'] || $version['epoch'] !== $report['epoch'] || $report['revision'] > $version['revision']) {
                throw new ResourceOperationException('websocket.report_conflict', 'The report does not match this runtime and registry.', 409);
            }
            if ($report['sequence'] <= $runtime->report_sequence) {
                return;
            }
            if ($runtime->applied_revision !== null && $report['revision'] < $runtime->applied_revision) {
                throw new ResourceOperationException('websocket.report_regressed', 'An applied revision cannot decrease.', 409);
            }
            $runtime->update([
                'epoch' => $report['epoch'], 'applied_revision' => $report['revision'], 'report_sequence' => $report['sequence'],
                'reported_at' => now(), 'state' => $report['state'],
                'error' => $report['error'] ?? null,
            ]);
        });
    }
}
