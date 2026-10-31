<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Routes\NativeRoutePublicationInput;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Public publication participants. Role removal never withdraws unrelated Route rows. */
final readonly class NodeRolePublicationInput
{
    public function __construct(private NativeRoutePublicationInput $publication, private NodeRoleFirewallInput $firewall) {}

    /** The role assignment already records its new desired state. Certificates and serving markers
     * are projected by the owning role root before the corresponding fresh publication.
     *
     * @return array{caddy_node_ids:list<int>,php_node_ids:list<int>,dns_node_ids:list<int>}
     */
    public function capture(Node $node, RoleName $role, ?Node $source = null): array
    {
        $caddy = in_array($role, CaddySiteRoles::CaddyRoles, true) ? [$node->id] : [];
        if ($source !== null && $caddy !== []) {
            $caddy[] = $source->id;
        }
        if ($role === RoleName::Metrics) {
            $caddy = array_map(static fn ($assignment): int => $assignment->node_id, CaddySiteRoles::serving(RoleName::Gateway));
        } elseif ($role === RoleName::Gateway) {
            // Reverb's site admits the Gateway address, so a Gateway transfer also republishes Reverb.
            $caddy = [...$caddy, ...array_map(static fn ($assignment): int => $assignment->node_id, CaddySiteRoles::serving(RoleName::WebSocket))];
        }
        $php = in_array($role, [RoleName::AppDev, RoleName::AppProd], true) ? [$node->id] : [];
        $dns = in_array($role, [RoleName::Gateway, RoleName::AppDev, RoleName::Metrics, RoleName::WebSocket, RoleName::Analytics], true)
            ? PrivateDnsPublicationNodes::query()->pluck('id')->all() : [];
        $ordered = static function (array $ids): array {
            $ids = array_values(array_unique($ids));
            sort($ids);

            return $ids;
        };

        return ['caddy_node_ids' => $ordered($caddy), 'php_node_ids' => $ordered($php), 'dns_node_ids' => $ordered($dns)];
    }

    /** @param array<string,mixed> $snapshot
     * @return list<int>
     */
    public static function participants(array $snapshot): array
    {
        $ids = array_values(array_unique([...ValidatedData::integerList($snapshot['caddy_node_ids']), ...ValidatedData::integerList($snapshot['php_node_ids']), ...ValidatedData::integerList($snapshot['dns_node_ids'])]));
        sort($ids);

        return $ids;
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        app(DirectOperationPrivateBindings::class)->assertParticipant($operation, $callerNodeId);
        if (! in_array($callerNodeId, ValidatedData::integerList($operation->input['participant_node_ids'] ?? [$operation->node_id]), true)) {
            throw new ResourceOperationException('operation.executor_forbidden', 'The Node is not an admitted role publication participant.', 403);
        }
    }

    /** Fresh data for this immutable participant only. Never includes role runtime or private references.
     * @return array<string,mixed>
     */
    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $this->assertPublicParticipant($operation, $callerNodeId);
        $snapshot = $this->snapshot($operation);
        $node = Node::query()->findOrFail($callerNodeId);
        $root = ValidatedData::object(match ($operation->kind) {
            'node.role_remove' => $operation->input['_role_remove'] ?? [],
            'node.role_relocate' => $operation->input['_relocate'] ?? [],
            default => $operation->input['_role'] ?? [],
        });
        if ($operation->kind === 'node.role_relocate' && ($root['source_node_id'] ?? null) === $callerNodeId) {
            $source = ValidatedData::object($root['source']);
            $inputSource = ['source_removal' => $source];
        } else {
            $inputSource = [];
        }
        $input = ['node_id' => $callerNodeId, ...$inputSource];
        if ($operation->kind === 'node.role_remove') {
            $input['role_removal'] = $root;
        } elseif ($operation->kind === 'node.role_relocate') {
            $input['role_removal'] = $root['source'];
        }
        if (isset($root['role'])) {
            $input['role'] = $root['role'];
            $input['metrics_fleet'] = $root['metrics_fleet'] ?? null;
            $input['proxy_node_ids'] = $root['proxy_node_ids'] ?? [];
        }

        return in_array($callerNodeId, self::participants($snapshot), true)
            ? [...$input, ...$this->forPlan($snapshot, $node)] : $input;
    }

    /** Fresh public artifacts only. The owning parent checks its immutable participant authority.
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function forPlan(array $plan, Node $node): array
    {
        $plan = ['caddy_node_ids' => ValidatedData::integerList($plan['caddy_node_ids'] ?? null),
            'php_node_ids' => ValidatedData::integerList($plan['php_node_ids'] ?? null),
            'dns_node_ids' => ValidatedData::integerList($plan['dns_node_ids'] ?? null)];
        if (! in_array($node->id, self::participants($plan), true)) {
            throw new ResourceOperationException('operation.executor_forbidden', 'The Node is not an admitted role publication participant.', 403);
        }
        $input = ['node_id' => $node->id];
        if (in_array($node->id, $plan['caddy_node_ids'], true) || in_array($node->id, $plan['php_node_ids'], true)) {
            $artifacts = $this->publication->forNode($node);
            if (in_array($node->id, $plan['caddy_node_ids'], true)) {
                $input['caddy_publication'] = $artifacts['caddy_publication'];
                $input['ingress_firewall'] = CaddySiteRoles::nodeServes($node->id, RoleName::Ingress)
                    ? $this->firewall->converge($node, RoleName::Ingress) : $this->firewall->remove($node, RoleName::Ingress);
            }
            if (in_array($node->id, $plan['php_node_ids'], true)) {
                $input['php_publication'] = $artifacts['php_publication'];
            }
        }
        if (in_array($node->id, $plan['dns_node_ids'], true)) {
            $input['private_dns_publication'] = $this->publication->dns($node);
        }

        return $input;
    }

    /** @return array{caddy_node_ids:list<int>,php_node_ids:list<int>,dns_node_ids:list<int>} */
    private function snapshot(Operation $operation): array
    {
        $field = match ($operation->kind) {
            'node.role_add', 'node.role_converge' => '_role',
            'node.role_remove' => '_role_remove',
            'node.role_relocate' => '_relocate',
            default => throw new ResourceOperationException('operation.executor_forbidden', 'The Operation is not a Node role root.', 403),
        };
        $input = ValidatedData::object($operation->input[$field] ?? []);

        $plan = ValidatedData::object($input['publication'] ?? null);

        return ['caddy_node_ids' => ValidatedData::integerList($plan['caddy_node_ids'] ?? null),
            'php_node_ids' => ValidatedData::integerList($plan['php_node_ids'] ?? null),
            'dns_node_ids' => ValidatedData::integerList($plan['dns_node_ids'] ?? null)];
    }
}
