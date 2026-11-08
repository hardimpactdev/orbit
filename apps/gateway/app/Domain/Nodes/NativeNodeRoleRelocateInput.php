<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Metrics\MetricsRoleInput;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Gateway\GatewayPrivateDnsResolver;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\WebSocket\WebSocketServiceUnitRenderer;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use App\Models\NodeRole;

/**
 * The full public intent of operation-node-role-relocate. The Gateway captures both halves at admission:
 * the target's runtime, certificates, firewall and publications, and the source removal that node:role:remove builds.
 */
final readonly class NativeNodeRoleRelocateInput
{
    public const string WebSocketUnit = '/etc/systemd/system/orbit-websocket.service';

    public function __construct(private NativeNodeRoleRemovalInput $removal, private SwampSshExecutionInput $ssh) {}

    /** Snapshots that must be read before the assignment moves.
     * @return array{target:list<array<string,mixed>>,source:list<array<string,mixed>>}
     */
    public function before(Node $target, Node $source, RoleName $role): array
    {
        return ['target' => $role === RoleName::Metrics ? [] : $this->removal->publications($target, $role, source: $source),
            'source' => $this->removal->publications($source, $role)];
    }

    /** Capture after the assignment names the target. No Node is contacted.
     * @param  array{target:list<array<string,mixed>>,source:list<array<string,mixed>>}  $before
     * @return array<string,mixed>
     */
    public function capture(Node $target, Node $source, NodeRole $assignment, array $before): array
    {
        $role = $assignment->role;
        if ($assignment->node_id !== $target->id || $source->is($target) || ! in_array($role, [RoleName::Gateway, RoleName::WebSocket, RoleName::Metrics], true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The relocated assignment must name its admitted target.', 422);
        }
        $ghost = new NodeRole(['node_id' => $source->id, 'role' => $role, 'status' => LifecycleStatus::Removing]);
        $ghost->setAttribute('id', $assignment->id);
        $ghost->setRelation('node', $source);
        $ssh = $this->ssh->forNode($target);
        $plan = app(NodeRoleCertificateInput::class)->capture($target, $role, $source);
        // Render as the run leaves it: the target holds its new certificate and the source has withdrawn its own.
        [$publications, $removal] = app(RouteRenderContext::class)->renderRoleCertificates($plan, fn (): array => [
            $role === RoleName::Metrics ? [] : $this->publications($target, $source, $role, $before['target']),
            $this->removal->capture($source, $ghost, $before['source'], desiredAssignment: $assignment)]);
        $websocket = $role === RoleName::WebSocket ? $this->websocket($target) : null;
        $firewall = $role === RoleName::Metrics ? ['desired' => [], 'remove' => []] : app(NodeRoleFirewallInput::class)->convergeCommands($target, $role);

        return ['schema_version' => 1, 'role' => $role->value, 'assignment_id' => $assignment->id,
            'target_node_id' => $target->id, 'source_node_id' => $source->id, 'ssh' => $ssh,
            'metrics' => $role === RoleName::Metrics ? $this->metrics($assignment) : null,
            'websocket' => $websocket, 'gateway' => $role === RoleName::Gateway ? $this->gateway($target) : null,
            'certificates' => $role === RoleName::Metrics ? ['leaves' => [], 'withdrawals' => []] : $this->certificates($target, $plan),
            'firewall' => $firewall, 'publications' => $publications,
            'source' => $removal,
            // Metrics follows its own lifecycle: its converge rebuilds the scrape configuration for the moved sites.
            'metrics_converge' => $role === RoleName::Metrics ? null
                : NodeRole::query()->where('role', RoleName::Metrics)->with('node')->first()?->node->name];
    }

    /** Every publishing Node but the source, whose withdrawal belongs to the source removal.
     * @param  list<array<string,mixed>>  $before
     * @return list<array<string,mixed>>
     */
    private function publications(Node $target, Node $source, RoleName $role, array $before): array
    {
        $result = [];
        foreach ($this->removal->publications($target, $role, source: $source) as $after) {
            if ($after['node_id'] === $source->id) {
                continue;
            }
            $prior = array_find($before, static fn (array $member): bool => $member['node_id'] === $after['node_id'])
                ?? throw new \LogicException('Role relocation needs the complete prior publication.');
            $result[] = ['node_id' => $after['node_id'], 'control_node_id' => SwampNode::id(),
                'host_keys' => $after['host_keys'], 'connection' => $after['connection'],
                'before' => array_intersect_key($prior, array_flip(['caddy', 'php'])),
                'after' => array_intersect_key($after, array_flip(['caddy', 'php', 'dns']))];
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function websocket(Node $target): array
    {
        $install = config('orbit.websocket.install_path');
        $port = config('orbit.websocket.port');
        if (! is_string($install) || preg_match('#\A(/[A-Za-z0-9._-]+){2,}\z#', $install) !== 1 || ! is_int($port)) {
            throw new ResourceOperationException('websocket.runtime_invalid', 'The Reverb install path or port is invalid.', 422);
        }
        $releases = $install.'.releases';
        $release = $releases.'/release.'.bin2hex(random_bytes(16));

        return ['user' => $target->user, 'install_path' => $install, 'releases' => $releases, 'release' => $release,
            'repository' => config('orbit.websocket.repository'), 'ref' => config('orbit.websocket.ref'), 'port' => $port,
            'unit' => ['path' => self::WebSocketUnit, 'content' => app(WebSocketServiceUnitRenderer::class)->render($install, $target->user, $target->user, $port)]];
    }

    /** A managed DNS peer keeps the resolver policy that peer convergence owns, so it gets no route.
     * @return array<string,mixed>|null
     */
    private function gateway(Node $target): ?array
    {
        $settings = app(VpnSettings::class);
        $resolver = new GatewayPrivateDnsResolver;
        $address = $resolver->vpnDnsAddress($settings);
        if ($address === null || app(PeerDnsPolicy::class)->manages($target)) {
            return null;
        }
        $domain = $settings->domain();

        return ['drop_in' => ['path' => GatewayPrivateDnsResolver::DROP_IN, 'content' => $resolver->dropIn($address, $domain)],
            'route' => [['resolvectl', 'domain', 'orbit', '~'.$domain], ['resolvectl', 'default-route', 'orbit', 'false'],
                ['resolvectl', 'dns', 'orbit', $address]]];
    }

    /** Metrics keeps its Gateway site and certificate, so its role workflow signs nothing here.
     * @return array<string,mixed>
     */
    private function metrics(NodeRole $assignment): array
    {
        return [...app(MetricsRoleInput::class)->capture($assignment, true, false), 'certificates' => ['leaves' => [], 'withdrawals' => []]];
    }

    /** Leaves on the target, each with the pinned SSH globals its certificate child uses.
     * @param  array{leaves:list<array{node_id:int,scope:string,domain:string,version:string,root_hash:string}>,withdrawals:list<array{node_id:int,scope:string}>}  $plan
     * @return array{leaves:list<array<string,mixed>>,withdrawals:list<array<string,mixed>>}
     */
    private function certificates(Node $target, array $plan): array
    {
        $leaves = [];
        foreach ($plan['leaves'] as $leaf) {
            $node = $leaf['node_id'] === $target->id ? $target : Node::query()->findOrFail($leaf['node_id']);
            $connection = $this->ssh->forNode($node);
            $leaves[] = [...$leaf, 'ssh' => ['name' => 'relocate-certificate-'.$node->id,
                'transport' => ['kind' => 'ssh', 'user' => $connection['target']['user'], 'port' => $connection['target']['port'], 'identityFile' => $connection['identity_file']],
                'hosts' => [['name' => 'target', 'address' => $connection['target']['host'], 'transport' => ['hostKeys' => $connection['target']['host_keys']]]]]];
        }

        // The source removal deletes the source's certificate; the projection records the withdrawal.
        return ['leaves' => $leaves, 'withdrawals' => $plan['withdrawals']];
    }
}
