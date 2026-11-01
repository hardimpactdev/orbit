<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\NodeRole;
use App\Models\WebSocketRegistryOperation;
use App\Models\WebSocketRuntime;
use App\Support\ValidatedData;

/** Freeze the existing registry change and every required serving target; no host effects. */
final readonly class WebSocketRegistryIntent
{
    public function __construct(private SwampSshExecutionInput $ssh, private SwampCommandStep $commands, private WebSocketDnsTarget $dns) {}

    /** @return array{native_registry:array<string,mixed>,execution_secrets:array<string,array{vault:string,key:string}>} */
    public function capture(WebSocketRegistryOperation $registry): array
    {
        $runtimes = WebSocketRuntime::query()->where('required', true)->with('node')->orderBy('id')->get();
        $role = NodeRole::query()->where('role', RoleName::WebSocket->value)->with('node')->first();
        $missing = [];
        foreach ($this->dns->servingNodes($role?->node) as $node) {
            if (! $runtimes->contains('node_id', $node->id)) {
                $missing[] = $node->id;
            }
        }
        $targets = $secrets = [];
        foreach ($runtimes as $runtime) {
            if ($runtime->slot !== 'default') {
                throw new ResourceOperationException('websocket.runtime_slot_unsupported', 'Native registry application requires the managed default runtime slot.', 409);
            }
            $field = 'runtime_'.str_replace('-', '_', $runtime->id);
            $targets[] = ['process_id' => $runtime->id, 'node_id' => $runtime->node_id, 'ssh' => $this->ssh->forNode($runtime->node),
                'port' => ValidatedData::integer(config('orbit.websocket.port')), 'credential_field' => $field];
        }

        foreach ($runtimes as $runtime) {
            $field = 'runtime_'.str_replace('-', '_', $runtime->id);
            $secrets[$field] = $this->commands->executionCopy('orbit-control-inputs', 'websocket-registry-'.$runtime->id, $runtime->registry_token);
        }

        return ['native_registry' => ['start_tag' => $registry->id, 'registry_operation_id' => $registry->id,
            'epoch' => $registry->epoch, 'revision' => $registry->revision, 'change' => $registry->kind,
            'app_id' => $registry->kind === 'sync' ? null : ($registry->result['app_id'] ?? null),
            'credential_generation' => $registry->kind === 'rotate' ? $registry->credential_generation : null,
            'runtimes' => $targets, 'missing_node_ids' => $missing], 'execution_secrets' => $secrets];
    }
}
