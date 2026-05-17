<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use App\Http\Gateway\GatewayApiException;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FirewallRuleIntent
{
    public function __construct(
        private readonly FirewallRuleQuery $query,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function store(string $action, string $name, string $nodeName, string $direction, string $source, ?string $destination, string $port, string $protocol, ?string $reason, ?Node $caller = null): array
    {
        $node = $this->resolveTargetNode($nodeName, $caller);
        $this->validateShape($action, $direction, $source, $destination, $port, $protocol);
        $this->guardBaselinePolicy($direction, $action, $source, $destination, $port, $protocol);

        $existing = FirewallRule::query()
            ->with('node')
            ->where('node_id', $node->id)
            ->where('name', $name)
            ->first();

        $shape = [
            'direction' => $direction,
            'action' => $action,
            'source' => $source,
            'destination' => $destination,
            'port' => $port,
            'protocol' => $protocol,
        ];

        if ($existing instanceof FirewallRule && ! $this->sameShape($existing, $shape)) {
            throw new GatewayApiException('A different firewall rule already uses this name on the selected node.', 'firewall_rule.name_collision', [
                'name' => $name,
                'node' => $node->name,
            ]);
        }

        $rule = FirewallRule::query()->updateOrCreate(
            ['node_id' => $node->id, 'name' => $name],
            [
                ...$shape,
                'reason' => $reason,
                'source_hash' => $this->sourceHash($node->name, $name, $shape, $reason),
            ],
        );

        return [
            'data' => [
                'rule' => $this->query->toRuleEntity($rule->refresh(), 'expected'),
            ],
            'meta' => [
                'action' => $existing instanceof FirewallRule ? 'converged' : 'created',
                'backend_enacted' => false,
                'warnings' => [$this->runtimeWarning($node->name)],
            ],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function remove(string $name, string $nodeName, ?Node $caller = null): array
    {
        $node = $this->resolveTargetNode($nodeName, $caller);

        $rule = FirewallRule::query()
            ->with('node')
            ->where('node_id', $node->id)
            ->where('name', $name)
            ->first();

        if (! $rule instanceof FirewallRule) {
            return [
                'data' => [
                    'rule' => [
                        'name' => $name,
                        'node' => $node->name,
                        'direction' => null,
                        'action' => null,
                        'source' => null,
                        'destination' => null,
                        'port' => null,
                        'protocol' => null,
                        'reason' => null,
                        'status' => 'already_absent',
                    ],
                ],
                'meta' => [
                    'backend_removed' => false,
                    'warnings' => [],
                ],
            ];
        }

        $entity = $this->query->toRuleEntity($rule, 'removed_with_drift');
        $rule->delete();

        return [
            'data' => [
                'rule' => $entity,
            ],
            'meta' => [
                'backend_removed' => false,
                'warnings' => [$this->cleanupWarning($node->name)],
            ],
        ];
    }

    private function resolveTargetNode(string $nodeName, ?Node $caller): Node
    {
        $node = Node::query()
            ->where('name', $nodeName)
            ->where('status', 'active')
            ->where('platform', 'ubuntu')
            ->where(function (Builder $query): void {
                $query
                    ->where('role', 'gateway')
                    ->orWhereIn('id', app(NodeRoleAssignments::class)->activeAppHostNodeIds());
            })
            ->first();

        if (! $node instanceof Node) {
            throw new GatewayApiException('The selected node is not a firewall target.', 'validation_failed', [
                'field' => 'node',
                'node' => $nodeName,
            ]);
        }

        $this->authorizeTargetNode($node, $caller);

        return $node;
    }

    private function authorizeTargetNode(Node $node, ?Node $caller): void
    {
        if (! $caller instanceof Node || $caller->role === 'gateway') {
            return;
        }

        $authorized = DB::table('node_access')
            ->where('consumer_node_id', $caller->id)
            ->where('serving_node_id', $node->id)
            ->exists();

        if ($authorized) {
            return;
        }

        throw new GatewayApiException('This node is not authorized to manage firewall rules for the selected node.', 'authorization_failed', [
            'node' => $node->name,
            'caller_role' => $caller->role,
        ]);
    }

    private function validateShape(string $action, string $direction, string $source, ?string $destination, string $port, string $protocol): void
    {
        if (! in_array($action, ['allow', 'deny'], true)) {
            throw new GatewayApiException('The firewall rule action is invalid.', 'validation_failed', ['field' => 'action']);
        }

        if (! in_array($direction, ['incoming', 'outgoing'], true)) {
            throw new GatewayApiException('The firewall rule direction is invalid.', 'validation_failed', ['field' => 'direction']);
        }

        if (! in_array($protocol, ['tcp', 'udp'], true)) {
            throw new GatewayApiException('The firewall rule protocol is invalid.', 'validation_failed', ['field' => 'protocol']);
        }

        if (! $this->validEndpoint($source) || ($destination !== null && ! $this->validEndpoint($destination))) {
            throw new GatewayApiException('The firewall rule endpoint is invalid.', 'validation_failed', ['field' => 'source']);
        }

        if (! preg_match('/^\d{1,5}(:\d{1,5})?$/', $port)) {
            throw new GatewayApiException('The firewall rule port is invalid.', 'validation_failed', ['field' => 'port']);
        }
    }

    private function validEndpoint(string $value): bool
    {
        return $value === 'any'
            || filter_var($value, FILTER_VALIDATE_IP) !== false
            || filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            || str_contains($value, '/');
    }

    private function guardBaselinePolicy(string $direction, string $action, string $source, ?string $destination, string $port, string $protocol): void
    {
        if ($direction === 'incoming' && $action === 'allow' && $source === 'any' && $destination === null && $protocol === 'tcp' && $port === '22') {
            throw new GatewayApiException('The requested rule would mutate node bootstrap policy.', 'firewall_rule.baseline_conflict', [
                'port' => $port,
                'protocol' => $protocol,
            ]);
        }
    }

    /**
     * @param  array<string, string|null>  $shape
     */
    private function sameShape(FirewallRule $rule, array $shape): bool
    {
        return $rule->direction === $shape['direction']
            && $rule->action === $shape['action']
            && $rule->source === $shape['source']
            && $rule->destination === $shape['destination']
            && $rule->port === $shape['port']
            && $rule->protocol === $shape['protocol'];
    }

    /**
     * @param  array<string, string|null>  $shape
     */
    private function sourceHash(string $node, string $name, array $shape, ?string $reason): string
    {
        return hash('sha256', json_encode([
            'node' => $node,
            'name' => $name,
            'shape' => $shape,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeWarning(string $node): array
    {
        return [
            'code' => 'firewall_rule.enactment_deferred',
            'family' => 'firewall_rule',
            'message' => 'Firewall rule intent was saved, but backend enactment is deferred until the firewall doctor enactor is ported.',
            'next_command' => "doctor --fix --family=firewall_rule --restore --node={$node}",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanupWarning(string $node): array
    {
        return [
            'code' => 'firewall_rule.cleanup_deferred',
            'family' => 'firewall_rule',
            'message' => 'Firewall rule intent was removed, but backend cleanup is deferred until the firewall doctor enactor is ported.',
            'next_command' => "doctor --fix --family=firewall_rule --restore --node={$node}",
        ];
    }
}
