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
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppDev\PrivateDnsMessageCodec;
use App\Infrastructure\AppDev\SocketPrivateDnsUpstream;
use App\Infrastructure\Gateway\GatewayPrivateDnsResolver;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Infrastructure\Routes\PublicProcessRouteFirewall;
use App\Infrastructure\Ssh\RemoteCommand;
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
        private DevelopmentSshExecutor $ssh,
        private CommandDeadline $deadline,
        private string $liveCaddyfilePath = '/etc/caddy/Caddyfile',
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
            $values = $this->observe($node, $this->command($route, $node, $upstream));
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

    /** @return array<string, ?bool> */
    private function observe(Node $node, RemoteCommand $command): array
    {
        try {
            $result = $this->ssh->execute(
                $node,
                $command,
                step: 'doctor-custom-proxy-route',
                errorCode: 'route.inspection_failed',
                commandTimeout: $this->deadline->cap(30.0),
            );
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }

        $values = [];
        foreach (explode("\n", trim($result->stdout)) as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
            if (is_string($key) && is_string($value) && in_array($value, ['0', '1', '2'], true)) {
                $values[$key] = match ($value) {
                    '0' => false,
                    '1' => true,
                    default => null,
                };
            }
        }

        return $values;
    }

    private function command(Route $route, Node $node, CustomProxyUpstream $upstream): RemoteCommand
    {
        $expectedDns = is_string($node->wireguard_ip) && $node->wireguard_ip !== ''
            ? $node->wireguard_ip
            : '';

        return new RemoteCommand(
            arguments: [
                'sudo',
                'bash',
                '-seu',
                '--',
                $route->domain,
                "/etc/caddy/orbit-certificates/route-{$route->id}/current",
                $upstream->authority(),
                $expectedDns,
                $upstream->host === '::1' ? '127.0.0.1' : $upstream->host,
                (string) $upstream->port,
                $this->liveCaddyfilePath,
            ],
            input: <<<'BASH'
                domain=$1
                certificates=$2
                upstream=$3
                expected_dns=$4
                upstream_host=$5
                upstream_port=$6
                # A Node Caddy build keeps every site in the one live file.
                live=$(readlink -f "$7")
                if grep -qs -- "$domain" "$live" && grep -qs -- "$upstream" "$live"; then
                    printf 'caddy=1\n'
                else
                    printf 'caddy=0\n'
                fi
                if test -f "$certificates/cert.pem" && test -f "$certificates/key.pem"; then
                    printf 'tls=1\n'
                else
                    printf 'tls=0\n'
                fi
                if [ "$expected_dns" = '' ]; then
                    printf 'dns=2\n'
                else
                    observed=$(getent ahostsv4 "$domain" 2>/dev/null | awk 'NR==1 { print $1 }') || observed=
                    if [ "$observed" = "$expected_dns" ]; then
                        printf 'dns=1\n'
                    else
                        printf 'dns=0\n'
                    fi
                fi
                if timeout 1 bash -c "echo >/dev/tcp/${upstream_host}/${upstream_port}" 2>/dev/null; then
                    printf 'upstream=1\n'
                else
                    printf 'upstream=0\n'
                fi
                BASH,
        );
    }
}
