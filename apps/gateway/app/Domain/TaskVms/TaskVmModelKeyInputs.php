<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Models\TaskVm;
use Closure;

/** Full model-step intent and execution-copy names for a Task VM parent. */
final readonly class TaskVmModelKeyInputs
{
    public function __construct(private ModelKeyIssuer $issuer) {}

    /** Capture once at parent admission; supply SwampCommandStep::executionCopy as the callback.
     *
     * @param  Closure(string,string,string):array{vault:string,key:string}  $executionCopy
     * @return array{intent:array{task_vm_id:int,gateway_node_id:int,model_router_node_id:int,model_origin:string},execution_secrets:array<string,array{vault:string,key:string}>}
     */
    public function capture(TaskVm $vm, int $gatewayNodeId, Closure $executionCopy): array
    {
        $origin = $vm->ai_router_origin;
        $key = $vm->model_key;
        if ($vm->id < 1 || $gatewayNodeId < 1 || ! is_string($origin) || ! $this->validOrigin($origin) || $origin !== $this->issuer->origin()
            || ! is_string($key) || preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1) {
            throw $this->unavailable();
        }
        $routerNodeId = $this->issuer->ownerNodeId();
        if ($routerNodeId < 1) {
            throw $this->unavailable();
        }
        $router = $this->issuer->privateMaterial($origin, $routerNodeId);
        if ($key === $router['anchor_key']) {
            throw $this->unavailable();
        }

        return [
            'intent' => ['task_vm_id' => $vm->id, 'gateway_node_id' => $gatewayNodeId,
                'model_router_node_id' => $routerNodeId, 'model_origin' => $origin],
            'execution_secrets' => [
                'model_key' => $executionCopy('orbit-control-inputs', 'task-vm-model-key-'.$vm->id, $key),
                'model_router_management' => $executionCopy('orbit-control-inputs', 'model-router-management-node-'.$routerNodeId, $router['management_key']),
                'model_router_anchor' => $executionCopy('orbit-control-inputs', 'model-router-anchor-node-'.$routerNodeId, $router['anchor_key']),
            ],
        ];
    }

    private function validOrigin(string $origin): bool
    {
        $url = parse_url($origin);

        return is_array($url) && in_array($url['scheme'] ?? null, ['http', 'https'], true)
            && is_string($url['host'] ?? null) && $url['host'] !== ''
            && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment'])
            && in_array($url['path'] ?? '', ['', '/'], true);
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('task_vm.model_key_failed', 'The admitted model key intent is unavailable.', 409);
    }
}
