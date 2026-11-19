<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\AppDev\PrivateDnsAnswerExpiry;
use App\Domain\Metrics\MetricsRoleInput;
use App\Domain\Routes\CustomProxyProcessListener;
use App\Domain\Routes\NativeRoutePublicationInput;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Gateway\GatewayPrivateDnsResolver;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Infrastructure\Routes\PublicProcessRouteFirewall;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\WebSocket\WebSocketServiceUnitRenderer;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/**
 * The full public intent of operation-node-role-relocate. The Gateway captures both halves at admission:
 * the target's runtime, certificates, firewall and publications, and the source removal that node:role:remove builds.
 */
final readonly class NativeNodeRoleRelocateInput
{
    public const string WebSocketUnit = '/etc/systemd/system/orbit-websocket.service';

    public function __construct(private NativeNodeRoleRemovalInput $removal, private SwampSshExecutionInput $ssh) {}

    /** Snapshots that must be read before the assignment moves.
     * @return array{target:list<array<string,mixed>>,source:list<array<string,mixed>>,dns:array<int,array<string,mixed>>}
     */
    public function before(Node $target, Node $source, RoleName $role): array
    {
        $dns = [];
        if ($role === RoleName::Metrics) {
            foreach (PrivateDnsPublicationNodes::query()->get() as $node) {
                $dns[$node->id] = app(NativeRoutePublicationInput::class)->dns($node);
            }
        }

        return ['target' => $role === RoleName::Metrics ? [] : $this->removal->publications($target, $role, source: $source),
            'source' => $this->removal->publications($source, $role), 'dns' => $dns];
    }

    /** Capture after the assignment names the target. No Node is contacted.
     * @param  array{target:list<array<string,mixed>>,source:list<array<string,mixed>>,dns:array<int,array<string,mixed>>}  $before
     * @return array<string,mixed>
     */
    public function capture(Node $target, Node $source, NodeRole $assignment, array $before, ?Operation $operation = null): array
    {
        $role = $assignment->role;
        if ($assignment->node_id !== $target->id || $source->is($target) || ! in_array($role, [RoleName::Gateway, RoleName::WebSocket, RoleName::Metrics, RoleName::Ingress], true)
            || ($role === RoleName::Ingress && ($operation === null || $assignment->cluster_id !== $target->cluster_id))) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The relocated assignment must name its admitted target.', 422);
        }
        $ghost = new NodeRole(['node_id' => $source->id, 'role' => $role, 'status' => LifecycleStatus::Removing]);
        $ghost->setAttribute('id', $assignment->id);
        $ghost->setRelation('node', $source);
        $ssh = $this->ssh->forNode($target);
        $plan = app(NodeRoleCertificateInput::class)->capture($target, $role, $source);
        // The Ingress role child owns every target effect, as the Metrics child does: Caddy and the Orbit root, sites, rules and DNS.
        $child = in_array($role, [RoleName::Metrics, RoleName::Ingress], true);
        // Render as the run leaves it: the target holds its new certificate and the source has withdrawn its own.
        [$publications, $removal] = app(RouteRenderContext::class)->renderRoleCertificates($plan, fn (): array => [
            $child ? [] : $this->publications($target, $source, $role, $before['target']),
            $this->removal->capture($source, $ghost, $before['source'], desiredAssignment: $assignment)]);
        $websocket = $role === RoleName::WebSocket ? $this->websocket($target) : null;
        $firewall = $child ? ['desired' => [], 'remove' => []] : app(NodeRoleFirewallInput::class)->convergeCommands($target, $role);
        $ingress = null;
        if ($role === RoleName::Ingress) {
            // The child serves the sites; the relocation's own steps probe the Process backends, check the public
            // certificates and only then switch private DNS, so a failure before that switch leaves the source serving alone.
            ['child' => $ingress, 'dns' => $publications] = $this->ingress($target, $source, $assignment, $before['target'], $operation);
        }

        return ['schema_version' => 1, 'role' => $role->value, 'assignment_id' => $assignment->id,
            'target_node_id' => $target->id, 'source_node_id' => $source->id, 'ssh' => $ssh,
            'metrics' => $role === RoleName::Metrics ? $this->metrics($assignment, $before['dns']) : null,
            'websocket' => $websocket, 'gateway' => $role === RoleName::Gateway ? $this->gateway($target) : null, 'ingress' => $ingress,
            'process_probes' => $role === RoleName::Ingress ? $this->processProbes($target) : [],
            'public_certificates' => $role === RoleName::Ingress ? $this->publicHostnames($target) : [],
            'certificates' => $child ? ['leaves' => [], 'withdrawals' => []] : $this->certificates($target, $plan),
            'firewall' => $firewall, 'publications' => $publications,
            // Private clients that hold the source's answer reach its sites until the answer expires.
            'grace' => ($websocket !== null || $ingress !== null) && array_any($publications, static fn (array $p): bool => ValidatedData::object($p['after'])['dns'] !== null)
                ? [['seconds' => PrivateDnsAnswerExpiry::WithdrawalGraceSeconds]] : [],
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
                'before' => array_intersect_key($prior, array_flip(['caddy', 'php', 'dns'])),
                'after' => array_intersect_key($after, array_flip(['caddy', 'php', 'dns']))];
        }

        return $result;
    }

    /** The role child's intent as `node:role:add TARGET ingress --converge` builds it, less two parts. The source's own
     * publication waits for its removal, so the source serves until then. Private DNS leaves the child for the relocation's
     * `dns` job, which runs after the backend probes and the certificate check. The relocation leaves Metrics to its
     * follow_up, so no fleet runtime or private material enters the child; the Ingress has no role certificate or execution secret.
     *
     * @param  list<array<string,mixed>>  $before
     * @return array{child:array<string,mixed>,dns:list<array<string,mixed>>}
     */
    private function ingress(Node $target, Node $source, NodeRole $assignment, array $before, Operation $operation): array
    {
        $snapshot = [...app(NodeRoleDirectInput::class)->capture($target, $assignment, false, includeCli: false), 'metrics_fleet' => null];
        $native = app(NativeNodeRoleAddInput::class)->capture($target, $assignment, $snapshot, $before, $operation, true);
        if ($native['execution_secrets'] !== [] || RouteExecutionProof::objects(ValidatedData::object($snapshot['certificates'])['leaves']) !== []) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Ingress relocation carries no role secret or certificate.', 422);
        }
        $intent = $native['intent'];
        $child = $dns = [];
        foreach (RouteExecutionProof::objects($intent['publications']) as $publication) {
            if (ValidatedData::integer($publication['node_id']) === $source->id) {
                continue;
            }
            $was = ValidatedData::object($publication['before']);
            $will = ValidatedData::object($publication['after']);
            if ($will['dns'] !== null) {
                $dns[] = [...array_intersect_key($publication, array_flip(['node_id', 'connection', 'host_keys'])),
                    'before' => ['dns' => $was['dns']], 'after' => ['caddy' => null, 'dns' => $will['dns']]];
            }
            if ($will['caddy'] !== null || $will['php'] !== null) {
                $child[] = [...$publication, 'before' => [...$was, 'dns' => null], 'after' => [...$will, 'dns' => null]];
            }
        }

        return ['child' => app(NodeConvergePhaseDigest::class)->stamp([...$intent, 'publications' => $child], []), 'dns' => $dns];
    }

    /** Each live public Process Route as the target will proxy it. The Process Node's recorded firewall must admit the
     * target, as at Route activation; the run then probes each upstream from the target before anything switches.
     *
     * @return list<array{domain:string,host:string,port:int}>
     */
    private function processProbes(Node $target): array
    {
        $probes = [];
        $routes = Route::query()->where('cluster_id', $target->cluster_id)->where('publication', RoutePublication::Public)->orderBy('id')->get()
            ->filter(static fn (Route $route): bool => $route->isCustomProxy() && app(PublicRouteEligibility::class)->publicEdgeIsLive($route));
        foreach ($routes as $route) {
            $process = $route->customProxy?->process;
            if ($process === null) {
                continue;
            }
            if (app(PublicProcessRouteFirewall::class)->expectation($route, $target)['expected'] === []) {
                throw new ResourceOperationException('route.upstream_firewall_mismatch',
                    "The Process Node firewall does not admit the new Ingress for [{$route->domain}].", 409);
            }
            $upstream = new CustomProxyProcessListener()->resolvePublic($process, $target);
            $probes[] = ['domain' => $route->domain, 'host' => $upstream->host, 'port' => $upstream->port];
        }

        return $probes;
    }

    /** Every name the target serves on its public listener once it holds the Ingress.
     * @return list<string>
     */
    private function publicHostnames(Node $target): array
    {
        $names = app(DevelopmentSiteRepository::class)->all()
            ->filter(static fn (DevelopmentSite $site): bool => $site->nodeId === $target->id && $site->publicListener)
            ->map(static fn (DevelopmentSite $site): string => $site->domain)->unique()->sort()->values()->all();

        return array_values($names);
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
     * @param  array<int,array<string,mixed>>  $dns
     * @return array<string,mixed>
     */
    private function metrics(NodeRole $assignment, array $dns): array
    {
        return [...app(MetricsRoleInput::class)->capture($assignment, true, false, $dns), 'certificates' => ['leaves' => [], 'withdrawals' => []]];
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
