<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Fleet\NodeFootprint;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Routes\CaddyPublicationPlan;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Fleet\Footprint\PhpRuntimeFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\TmpfilesFootprintArtifact;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Nodes\NodePrivateDnsListenerFootprint;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteRemovalResidue;
use App\Support\ValidatedData;
use Illuminate\Container\Attributes\Tag;

/** Captures desired records and templates only. It never probes or changes a Node. */
final readonly class NodeConvergeInput
{
    public const string GroupToken = '__orbit_converge_group__';

    public const string HomeToken = '/__orbit_converge_home__';

    /** @param iterable<NodeFootprintArtifact> $artifacts */
    public function __construct(private NodeFootprint $footprint, private AgentDelivery $delivery,
        #[Tag(NodeFootprintArtifact::class)] private iterable $artifacts) {}

    /** @return array<string,mixed> */
    public function capture(Node $node, bool $force, bool $firstVisit): array
    {
        $expected = $this->footprint->expected($node)->artifacts;
        $record = $this->footprint->recorded($node);
        $recorded = $record === null ? [] : ($record->artifacts ?? []);
        $plan = [];
        $payloads = [];
        $refusal = null;
        $network = null;
        foreach ($this->artifacts as $artifact) {
            $name = $artifact->name();
            if ($name === 'private-dns') {
                if (! PrivateDnsPublicationNodes::query()->whereKey($node->id)->exists()) {
                    continue;
                }
                try {
                    $expected[$name] = hash('sha256', $this->delivery->pin($node)."\0".$artifact->digest($node));
                } catch (\Throwable) {
                    $expected[$name] = 'unavailable:private-dns';
                }
            }
            if (! array_key_exists($name, $expected)) {
                continue;
            }
            $digest = $expected[$name];
            $repair = ($recorded[$name] ?? null) === $digest;
            $required = $force || ! $repair || in_array($name, ['agent', 'caddy', 'peer-dns'], true);
            $plan[] = compact('name', 'digest', 'required', 'repair');
            if (str_starts_with($digest, 'unavailable:')) {
                $refusal = 'node.footprint_failed';

                continue;
            }
            if (! $required) {
                continue;
            }
            if ($name === 'peer-dns') {
                $policy = app(PeerDnsPolicy::class);
                $server = $policy->server($node);
                if (! Ipv4Subnet::from(app(VpnSettings::class)->subnet())->containsUsableAddress($server)) {
                    $refusal = 'vpn.configuration_invalid';

                    continue;
                }
                $network = ['schema_version' => 1, 'node_id' => $node->id, 'agent_sha256' => $this->delivery->pin($node),
                    'peer_dns' => ['dns_server' => $server, 'public_key' => $node->wireguard_public_key, 'domains' => $policy->domains($node)]];

                continue;
            }
            $payloads[$name] = match ($name) {
                'agent' => [...app(NodeAgentSshExecutor::class)->configurationForRoot($node, null),
                    'user' => $node->user, 'apps_root' => ValidatedData::object($node->settings['apps'] ?? [])['path'] ?? null,
                    'checkouts' => $node->instances()->with('project')->orderBy('id')->get()->map(static fn ($instance): string => $instance->applicationDirectory())->all(),
                    'url' => $this->delivery->artifacts()[AgentDelivery::key($node)]['url'] ?? null],
                'caddy-package' => AgentCaddyPackage::input(),
                'caddy' => $this->caddy($node),
                'private-dns' => PrivateDnsPublicationNodes::query()->whereKey($node->id)->exists()
                    ? ['listener' => app(NodePrivateDnsListenerFootprint::class)->input($node), 'publication' => $this->dns()]
                    : ['error_code' => 'node.private_dns_owner_changed'],
                'route-residue' => $this->residue($node),
                'php-runtime' => ['configurations' => app(PhpRuntimeFootprintArtifact::class)->inputs($node)],
                'tmpfiles' => ['rule' => TmpfilesFootprintArtifact::Rule],
                default => ['error_code' => 'operation.kind_unavailable'],
            };
            if (! in_array($name, ['agent', 'caddy-package', 'caddy', 'private-dns', 'route-residue', 'php-runtime', 'tmpfiles'], true)) {
                $refusal = 'operation.kind_unavailable';
            }
        }

        return ['schema_version' => 1, 'node_id' => $node->id, 'agent_sha256' => $this->delivery->pin($node),
            'force_artifacts' => $force, 'first_visit' => $firstVisit, 'artifacts' => $plan,
            'payloads' => $payloads, 'network' => $network, 'refusal' => $refusal,
            'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $node->id]];
    }

    /** @return array<string,mixed> */
    private function caddy(Node $node): array
    {
        $render = app(NodeCaddyfileRenderer::class)->render($node);

        return $render->buildable() ? CaddyPublicationPlan::input($render, $node->id) : ['error_code' => 'caddy.render_failed', 'stage' => 'render'];
    }

    /** @return array{records:string,catalog:string} */
    private function dns(): array
    {
        $renderer = app(DevelopmentDnsConfigRenderer::class);

        return ['records' => $renderer->render(), 'catalog' => json_encode([
            'requesters' => $renderer->registeredRequesters(), ...$renderer->catalog()->toPublished(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n"];
    }

    /** @return array<string,mixed> */
    private function residue(Node $node): array
    {
        $rows = RouteRemovalResidue::query()->where('node_id', $node->id)->orderBy('id')->get()
            ->filter(static fn (RouteRemovalResidue $row): bool => ! Route::query()->whereKey($row->route_id)->exists())->values();
        $sites = app(DevelopmentSiteRepository::class)->forNode($node);
        $configurations = [];
        if ($rows->contains(static fn (RouteRemovalResidue $row): bool => in_array('php', $row->steps, true))) {
            foreach ($sites->filter(static fn (DevelopmentSite $site): bool => $site->phpVersion !== null && ! $site->isProxy() && ! $site->usesDedicatedPhpRuntime())->groupBy('phpVersion') as $version => $members) {
                $configurations[$version] = app(DevelopmentPhpFpmConfigRenderer::class)->render($members->values(),
                    new ManagedUserAccount($node->user, self::GroupToken, self::HomeToken));
            }
        }
        $certificates = [];
        $firewall = [];
        foreach ($rows as $row) {
            if (in_array('certificates', $row->steps, true)) {
                foreach (["route-{$row->route_id}", "route-{$row->route_id}-router", "route-{$row->route_id}-router-hostname-change", "route-{$row->route_id}-ingress"] as $scope) {
                    if (! $sites->contains(static fn (DevelopmentSite $site): bool => $site->loadsCertificate($scope))) {
                        $certificates[] = $scope;
                    }
                }
            }
            if (in_array('firewall', $row->steps, true) && ! $sites->contains(static fn (DevelopmentSite $site): bool => $site->domain === $row->domain)) {
                $firewall[] = ['operation' => 'remove', 'route_id' => $row->route_id];
            }
        }

        return ['rows' => $rows->map(static fn (RouteRemovalResidue $row): array => $row->only(['id', 'route_id', 'domain', 'steps', 'attempts']))->all(),
            'caddy' => $this->caddy($node), 'user' => $node->user,
            'php' => $rows->contains(static fn (RouteRemovalResidue $row): bool => in_array('php', $row->steps, true)) ? (object) $configurations : null,
            'certificates' => $certificates, 'firewall' => $firewall,
            'ingress' => $firewall !== [] && $node->roles()->where('role', RoleName::Ingress)->exists()
                ? app(NodeRoleFirewallInput::class)->converge($node, RoleName::Ingress) : null,
            'dns' => PrivateDnsPublicationNodes::query()->whereKey($node->id)->exists() ? $this->dns() : null];
    }
}
