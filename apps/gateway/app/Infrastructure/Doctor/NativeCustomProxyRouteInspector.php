<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\CustomProxyRouteInspector;
use App\Domain\Doctor\CustomProxyRouteObservation;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\PublicRouteEdgeInspector;
use App\Domain\Routes\CustomProxyUpstream;
use App\Domain\Routes\RoutePublication;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AppDev\PrivateDnsMessageCodec;
use App\Infrastructure\AppDev\SocketPrivateDnsUpstream;
use App\Infrastructure\Gateway\GatewayPrivateDnsResolver;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Infrastructure\Routes\PublicProcessRouteFirewall;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteCustomProxy;
use Throwable;

/**
 * Reads the live Caddyfile as root, because published versions under /etc/caddy/orbit-versions are root:caddy 0750.
 */
final readonly class NativeCustomProxyRouteInspector implements CustomProxyRouteInspector
{
    public function __construct(
        private AgentCommandRunner $agents,
        private CommandDeadline $deadline,
    ) {}

    public function inspect(RouteCustomProxy $proxy): CustomProxyRouteObservation
    {
        $proxy->loadMissing(['route', 'node']);
        $route = $proxy->route;
        $node = $proxy->node;

        if (! $route instanceof Route || ! $node instanceof Node) {
            throw new DoctorInspectionException;
        }

        try {
            if ($route->publication === RoutePublication::Public) {
                return $this->inspectPublic($route);
            }
            $upstream = CustomProxyUpstream::parse($proxy->upstream);
            $values = $this->agents->run($node, 'doctor.inspect-custom-proxy', [
                'domain' => $route->domain,
                'certificates' => "/etc/caddy/orbit-certificates/route-{$route->id}/current",
                'upstream' => $upstream->authority(),
                'dns_address' => $node->wireguard_ip,
            ], $this->deadline->cap(30.0));
            foreach (['dns', 'tls', 'caddy', 'upstream'] as $field) {
                if (! array_key_exists($field, $values) || ($values[$field] !== null && ! is_bool($values[$field]))) {
                    throw new DoctorInspectionException;
                }
            }
        } catch (DoctorInspectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }

        return new CustomProxyRouteObservation(
            dnsMatches: $values['dns'] ?? null,
            certificateMatches: $values['tls'] ?? null,
            caddyMatches: $values['caddy'] ?? null,
            upstreamReachable: $values['upstream'] ?? null,
        );
    }

    private function inspectPublic(Route $route): CustomProxyRouteObservation
    {
        $node = new IngressSiteRepository()->ingressNode($route);
        // Keep independent observations when a not-yet-published site cannot be inspected.
        try {
            $backendFirewall = app(PublicProcessRouteFirewall::class)->matches($route, $node);
        } catch (Throwable) {
            $backendFirewall = null;
        }
        try {
            $edge = app(PublicRouteEdgeInspector::class)->inspect($node, $route);
        } catch (DoctorInspectionException) {
            $edge = null;
        }
        try {
            $server = app(GatewayPrivateDnsResolver::class)->vpnDnsAddress(app(VpnSettings::class));
            if ($server === null) {
                throw new DoctorInspectionException;
            }
            $codec = new PrivateDnsMessageCodec;
            $query = $codec->encodeQuery($route->domain, id: random_int(0, 65535));
            $upstream = app()->makeWith(SocketPrivateDnsUpstream::class, [
                'host' => $server,
                'port' => 53,
                'timeoutSeconds' => $this->deadline->cap(2.0),
            ]);
            $addresses = $codec->decodeAAnswer($upstream->resolve($query), $query);
            $dnsMatches = $addresses === [$node->wireguard_ip];
        } catch (Throwable) {
            $dnsMatches = null;
        }
        $firewallMatches = $backendFirewall === false || $edge?->firewallMatches === false
            ? false
            : ($backendFirewall === true && $edge?->firewallMatches === true ? true : null);

        return new CustomProxyRouteObservation(
            dnsMatches: $dnsMatches,
            certificateMatches: $edge?->publicTlsMatches,
            caddyMatches: $edge?->ingressProjectionMatches,
            upstreamReachable: $edge?->privateForwardingMatches,
            firewallMatches: $firewallMatches,
        );
    }
}
