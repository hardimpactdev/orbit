<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Certificates\CertificateSigningRequests;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Public admitted resources and minimal state projection. Swamp owns the complete DAG. */
final readonly class RouteOperationProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public const array Kinds = ['route.workload_verify', 'route.public_verify'];

    public const array LocalKinds = ['route.lan_remove'];

    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return $this->publicInput($operation, $operation->node_id);
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        if (! in_array($callerNodeId, $operation->input['participant_node_ids'] ?? [$operation->node_id], true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This Node is not an admitted Route participant.', 403);
        }
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $this->assertPublicParticipant($operation, $callerNodeId);
        $node = Node::query()->findOrFail($callerNodeId);
        $input = $operation->input ?? [];
        $public = match ($operation->kind) {
            'route.create', 'route.converge' => app(NativeRouteConvergenceIntent::class)->publicInput($operation, $callerNodeId),
            'route.update' => app(NativeRouteUpdateIntent::class)->publicInput($operation, $callerNodeId),
            'route.target_set' => app(NativeRouteTargetIntent::class)->publicInput($operation, $callerNodeId),
            'route.destroy', 'route.withdraw' => [...app(NativeRoutePublicationInput::class)->forNode($node),
                ...app(NativeRouteRemovalPublicInput::class)->forNode($operation, $callerNodeId),
                'publication' => ['node_id' => $node->id, 'agent_sha256' => app(AgentDelivery::class)->pin($node)]],
            'route.caddy_converge', 'route.private_dns_publish' => app(NativeRoutePublicationInput::class)->forNode($node),
            'route.lan_remove' => Arr::only(ValidatedData::object($input['lan_removal'] ?? []), ['route_id', 'agent_sha256']),
            'route.target_unset' => ['route_id' => $operation->target_id, 'target_ids' => []],
            default => ValidatedData::object($input['verification'] ?? []),
        };

        return [...$public, 'environment_fields' => $input['environment_fields'] ?? [],
            'participant_node_id' => $callerNodeId];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = ValidatedData::object($data['result'] ?? []);
        if (isset($result['certificate_requests'])) {
            app(CertificateSigningRequests::class)->record($operation, $result['certificate_requests']);
        } elseif (($data['status'] ?? 'running') !== 'cancelled' && ($result !== [] || ($data['status'] ?? 'running') !== 'running')) {
            $result = match ($operation->kind) {
                'route.create', 'route.converge' => app(NativeRouteConvergenceProjection::class)->project($operation, $result),
                'route.update' => app(NativeRouteUpdateProjection::class)->project($operation, $result),
                'route.target_set' => app(NativeRouteTargetProjection::class)->project($operation, $result),
                'route.destroy', 'route.withdraw' => app(NativeRouteRemovalProjection::class)->project($operation, $data),
                default => $result,
            };
        }

        return $this->direct->project($operation, $data, $result);
    }
}
