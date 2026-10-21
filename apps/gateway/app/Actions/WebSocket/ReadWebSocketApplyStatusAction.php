<?php

declare(strict_types=1);

namespace App\Actions\WebSocket;

use App\Domain\Nodes\RoleName;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Infrastructure\WebSocket\WebSocketRegistryNotifier;
use App\Models\NodeRole;
use App\Models\WebSocketApp;
use App\Models\WebSocketRegistryOperation;
use App\Models\WebSocketRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

final readonly class ReadWebSocketApplyStatusAction
{
    public function __construct(private WebSocketRegistryNotifier $notifier, private WebSocketDnsTarget $dns, private float $waitSeconds = 5) {}

    /** @return array<string, mixed> */
    public function wait(WebSocketRegistryOperation $operation): array
    {
        $deadline = microtime(true) + $this->waitSeconds;
        $this->notifier->notify($operation->epoch, $operation->revision);
        do {
            $status = $this->execute($operation);
            if ($status['state'] !== 'pending' || microtime(true) >= $deadline) {
                return $status;
            }
            Sleep::usleep(50000);
        } while (true);
    }

    /** @return array<string, mixed> */
    public function execute(WebSocketRegistryOperation $operation): array
    {
        return DB::transaction(fn (): array => $this->read($operation));
    }

    /** @return array<string, mixed> */
    private function read(WebSocketRegistryOperation $operation): array
    {
        $runtimes = WebSocketRuntime::query()->where('required', true)->orderBy('id')->get();
        $role = NodeRole::query()->where('role', RoleName::WebSocket->value)->with('node')->first();
        $missing = [];
        foreach ($this->dns->servingNodes($role?->node) as $node) {
            if (! $runtimes->contains('node_id', $node->id)) {
                $missing[] = $node->id;
            }
        }
        $allApplied = $runtimes->isNotEmpty() && $missing === [];
        $processes = [];
        foreach ($runtimes as $runtime) {
            $fresh = $runtime->reported_at !== null && $runtime->reported_at->greaterThan(now()->subSeconds(15));
            $applied = $fresh && $runtime->state === 'serving' && $runtime->epoch === $operation->epoch && $runtime->applied_revision >= $operation->revision;
            $allApplied = $allApplied && $applied;
            $processes[] = [
                'id' => $runtime->id, 'node_id' => $runtime->node_id, 'boot_id' => $runtime->boot_id,
                'applied_revision' => $runtime->applied_revision, 'state' => $applied ? 'applied' : ($fresh ? 'pending' : 'stale'),
            ];
        }

        return [
            'id' => $operation->id, 'epoch' => $operation->epoch, 'revision' => $operation->revision,
            'state' => ! $this->preservesResult($operation) ? 'superseded' : ($allApplied ? 'applied' : 'pending'), 'processes' => $processes, 'missing_node_ids' => $missing,
            'status_url' => '/api/v1/websocket-operations/'.$operation->id,
        ];
    }

    private function preservesResult(WebSocketRegistryOperation $operation): bool
    {
        if ($operation->kind === 'sync') {
            return true;
        }
        $app = WebSocketApp::query()->where('app_id', $operation->result['app_id'])->first();
        if ($operation->kind === 'destroy') {
            return $app === null;
        }

        return $app instanceof WebSocketApp && ($operation->kind !== 'rotate' || $app->credential_generation === $operation->credential_generation);
    }
}
