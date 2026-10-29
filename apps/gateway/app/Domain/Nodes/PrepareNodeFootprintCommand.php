<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Fleet\FleetNodeVerification;
use App\Domain\Fleet\FleetNodeVerifier;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Fleet\Footprint\TmpfilesFootprintArtifact;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Nodes\NodePrivateDnsListenerFootprint;
use App\Infrastructure\WireGuard\PeerDnsInspector;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use App\Models\NodeFootprint as NodeFootprintRecord;
use App\Models\Operation;
use Illuminate\Container\Attributes\Tag;

/** Renders and records a footprint snapshot. It performs no Node mutation. */
final readonly class PrepareNodeFootprintCommand implements OperationGatewayCommand
{
    /** @param iterable<NodeFootprintArtifact> $artifacts */
    public function __construct(
        private NodeFootprint $footprint,
        private FleetNodeVerification $verification,
        private NodeAgentSshExecutor $agent,
        private PeerDnsPolicy $dns,
        private PeerDnsInspector $dnsInspector,
        private OperationStore $operations,
        #[Tag(NodeFootprintArtifact::class)] private iterable $artifacts,
    ) {}

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false,
            'required' => ['force_artifacts', 'first_visit'],
            'properties' => ['force_artifacts' => ['type' => 'boolean'], 'first_visit' => ['type' => 'boolean']]];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        if (($operation->state['node_footprint_prepared'] ?? false) === true) {
            return ['prepared' => true];
        }
        $node = Node::query()->findOrFail($operation->node_id);
        if (! app(ManagedNodeEligibility::class)->allows($node)) {
            throw new ResourceOperationException('node.converge_unsupported', 'Only an active, managed Linux Node has a Gateway-rendered footprint.', 422);
        }
        $expected = $this->footprint->expected($node);
        $record = $this->footprint->recorded($node);
        $recorded = $record instanceof NodeFootprintRecord ? ($record->artifacts ?? []) : [];
        $repairs = FleetNodeVerifier::repairs($this->verification->baseline($node));
        if ($node->agent_failed_pin !== null) {
            $repairs[] = 'agent';
        }
        $plan = [];
        $payloads = [];
        foreach ($this->artifacts as $artifact) {
            $name = $artifact->name();
            if (! array_key_exists($name, $expected->artifacts)) {
                continue;
            }
            $digest = $expected->artifacts[$name];
            if (str_starts_with($digest, 'unavailable:')) {
                return ['error_code' => 'node.footprint_failed'];
            }
            $matches = ($recorded[$name] ?? null) === $digest;
            $required = ($input['force_artifacts'] ?? false) === true || in_array($name, $repairs, true) || ! $matches;
            if ($required && $name === 'peer-dns' && $this->dnsInspector->inspect($node)['policy']) {
                $required = false;
            }
            $plan[] = ['name' => $name, 'digest' => $digest, 'required' => $required, 'repair' => $matches];
            if (! $required) {
                continue;
            }
            if ($name === 'private-dns') {
                $payloads['private-dns-listener'] = app(NodePrivateDnsListenerFootprint::class)->input($node);
            }
            $payloads[$name] = match ($name) {
                'agent' => [...$this->agent->configuration($node),
                    'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $node->id]],
                'caddy-package' => AgentCaddyPackage::input(),
                'tmpfiles' => ['rule' => TmpfilesFootprintArtifact::Rule],
                'peer-dns' => ['dns_server' => $this->dns->server($node),
                    'public_key' => (string) $node->wireguard_public_key, 'domains' => $this->dns->domains($node)],
                default => [],
            };
        }
        $children = [];
        foreach (['caddy' => 'route.caddy_converge', 'private-dns' => 'route.private_dns_publish',
            'route-residue' => 'route.offline_residue_clean', 'ai-router' => 'ai_router.collector_converge'] as $artifact => $kind) {
            if (! array_any($plan, static fn (array $item): bool => $item['name'] === $artifact && $item['required'])) {
                continue;
            }
            $children[$artifact] = ['kind' => $kind, 'node_id' => $node->id, 'input' => match ($artifact) {
                'route-residue' => ['removed_node_id' => null],
                'ai-router' => [],
                default => ['reason' => 'node-footprint:'.$operation->id.':'.$artifact],
            }];
        }
        $intents = [];
        foreach ($children as $artifact => $intent) {
            $intents[] = ['key' => 'footprint-'.$artifact, ...$intent];
        }
        $snapshot = ['node_id' => $node->id, 'agent_pin' => app(AgentDelivery::class)->pin($node), 'artifacts' => $plan, 'payloads' => $payloads, 'recorded' => $recorded,
            'children' => $children];
        $this->operations->change($operation, [
            'input' => [...$operation->input, '_footprint' => $snapshot],
            'state' => [...($operation->state ?? []), 'node_footprint_prepared' => true, 'child_intents' => $intents],
        ]);

        return ['prepared' => true];
    }
}
