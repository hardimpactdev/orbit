<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\PublicRouteEdgeInspector;
use App\Domain\Doctor\PublicRouteEdgeObservation;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use App\Models\Route;
use Throwable;

/**
 * Compares the live public site with the site the Project development publisher renders for the Ingress
 * Node, and the Ingress firewall with its managed public HTTP rules.
 *
 * The expected site comes from the Node Caddy build's render of the Ingress Node, so a
 * separate Ingress expects a reverse proxy to the Router, and an Ingress that shares the Router and
 * the workload expects the composed site that serves the Instance directly. The probe reads the live
 * Caddy version as root, because published versions under /etc/caddy/orbit-versions are
 * root:caddy 0750.
 */
final readonly class NativePublicRouteEdgeInspector implements PublicRouteEdgeInspector
{
    public function __construct(
        private AgentCommandRunner $agents,
        private DevelopmentSiteRepository $sites = new DevelopmentSiteRepository,
        private ?NodeCaddyfileRenderer $builds = null,
        private NodeFirewallRuleCatalog $firewall = new NodeFirewallRuleCatalog,
        private UfwManagedRulesCheck $ufw = new UfwManagedRulesCheck,
    ) {}

    public function inspect(Node $node, Route $route): PublicRouteEdgeObservation
    {
        try {
            $site = $this->publicSite($node, $route);
            $result = $this->agents->run($node, 'doctor.inspect-public-route', [
                'domain' => $site->domain,
                'expected_site' => $this->expectedBlock($node, $site),
                'certificates' => $site->certificateDirectory(),
                'forwarding' => $this->forwardingTargets($site),
            ]);
            foreach (['ingress', 'forwarding', 'tls'] as $key) {
                if (! is_bool($result[$key] ?? null)) {
                    throw new DoctorInspectionException;
                }
            }
            $firewallMatches = $this->firewallMatches($node);
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }

        return new PublicRouteEdgeObservation(
            ingressProjectionMatches: $result['ingress'],
            privateForwardingMatches: $result['forwarding'],
            publicTlsMatches: $result['tls'],
            firewallMatches: $firewallMatches,
        );
    }

    private function publicSite(Node $node, Route $route): DevelopmentSite
    {
        $site = $this->sites->forNode($node)->first(
            static fn (DevelopmentSite $candidate): bool => $candidate->publicListener
                && $candidate->domain === $route->domain,
        );

        if (! $site instanceof DevelopmentSite) {
            throw new DoctorInspectionException;
        }

        return $site;
    }

    /**
     * The `host:port` pairs the public site forwards to over TCP. Caddy proxies each address over HTTPS, so an
     * address without a port uses the HTTPS port. Unix socket upstreams stay on the Node and are not forwarding
     * targets.
     *
     * @return list<string>
     */
    private function forwardingTargets(DevelopmentSite $site): array
    {
        $targets = [];
        if ($site->localHttpUpstream !== null) {
            $targets[] = $site->localHttpUpstream;
        }

        foreach ($site->proxyAddresses() as $address) {
            if (str_starts_with($address, 'unix/')) {
                continue;
            }

            $host = parse_url("https://{$address}", PHP_URL_HOST);
            $port = parse_url("https://{$address}", PHP_URL_PORT);

            if (! is_string($host) || $host === '') {
                throw new DoctorInspectionException;
            }

            $targets[] = $host.':'.(is_int($port) ? $port : 443);
        }

        return $targets;
    }

    /** The public site as this Node's Caddy build renders it. Rust owns comparison with the live site. */
    private function expectedBlock(Node $node, DevelopmentSite $site): string
    {
        $block = ($this->builds ?? app(NodeCaddyfileRenderer::class))->render($node)->blocksFor($site->scope)[0] ?? null;

        if (! is_string($block)) {
            throw new DoctorInspectionException;
        }

        return $block;
    }

    /** The Ingress firewall matches when every managed public HTTP rule is present exactly. */
    private function firewallMatches(Node $node): bool
    {
        $rules = $this->firewall->forRole($node, RoleName::Ingress);

        if ($rules === []) {
            return true;
        }

        $status = $this->agents->run($node, 'firewall.status', ['stored' => false]);
        if (! is_string($status['status'] ?? null)) {
            throw new DoctorInspectionException;
        }
        $result = new CommandResult(0, $status['status'], '', 0, false);

        return $this->ufw->matches($result, $rules);
    }
}
