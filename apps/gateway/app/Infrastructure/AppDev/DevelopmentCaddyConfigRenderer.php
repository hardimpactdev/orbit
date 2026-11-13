<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DevelopmentServerEndpoint;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\InstanceDevPorts;
use Illuminate\Support\Collection;

final readonly class DevelopmentCaddyConfigRenderer
{
    public const string ORBIT_ROOT_CA_PATH = '/usr/local/share/ca-certificates/orbit-managed-root-ca.crt';

    public function __construct(
        private string $gatewayOrigin = 'https://gateway.orbit',
    ) {}

    /**
     * @param  Collection<int, DevelopmentSite>  $sites
     * @param  string  $bind  The listener of private sites.
     * @param  string  $publicBind  The listener of public sites: every address, and the WireGuard address too on a
     *                              Node where a WireGuard-only site shares their port.
     */
    public function render(Collection $sites, string $bind = '0.0.0.0', string $publicBind = '0.0.0.0'): string
    {
        if ($sites->isEmpty()) {
            return '# Orbit has no active app development sites.'.PHP_EOL;
        }

        return
            $sites
                ->sortBy('domain')
                ->map(function (DevelopmentSite $site) use ($bind, $publicBind): string {
                    $handler = $this->websocketHandler($site).$this->handler($site);
                    $internal = $this->localUnixSite($site);

                    // Orbit disables certificate management Node-wide; only a public listener opts back
                    // in, so Caddy asks a public CA for public hostnames alone (ADR 0138).
                    $siteBlock = $site->publicListener
                        ? <<<CADDY
                            {$site->domain} {
                                bind {$publicBind}
                                tls force_automate
                                {$handler}
                            }
                            CADDY
                        : <<<CADDY
                            https://{$site->domain} {
                                bind {$bind}
                                tls {$site->certificateDirectory()}/cert.pem {$site->certificateDirectory()}/key.pem
                                {$handler}
                            }
                            CADDY;

                    return $internal === null ? $siteBlock : $internal.PHP_EOL.PHP_EOL.$siteBlock;
                })
                ->implode(PHP_EOL.PHP_EOL).PHP_EOL;
    }

    /**
     * Reserve Pusher's prefixes on this app domain. Reverb verifies signatures; Caddy binds both
     * protocols to this attachment's public identifiers. No secret enters the Node configuration.
     * Match the decoded, uncleaned path: path/path_regexp clean dot segments before matching,
     * while reverse_proxy forwards them. The path matcher also ignores case.
     * Caddy 2.11.7 does not advertise RFC 8441; revisit GET/Upgrade if a later version enables it.
     */
    private function websocketHandler(DevelopmentSite $site): string
    {
        if ($site->websocketKey === null || $site->websocketAppId === null) {
            return '';
        }

        $key = $site->websocketKey;
        $id = preg_quote($site->websocketAppId, '~');
        $root = self::ORBIT_ROOT_CA_PATH;
        $proxy = <<<CADDY
            reverse_proxy https://reverb.orbit:443 {
                header_up Host reverb.orbit
                transport http {
                    tls_server_name reverb.orbit
                    tls_trusted_ca_certs {$root}
                }
            }
            CADDY;

        return <<<CADDY
            route {
                @orbit_reverb_socket {
                    method GET
                    vars {http.request.uri.path} /app/{$key}
                    header_regexp Connection (?i)(^|,)\\s*upgrade\\s*(,|$)
                    header_regexp Upgrade (?i)^websocket$
                }
                handle @orbit_reverb_socket {
                    {$proxy}
                }
                @orbit_reverb_publish {
                    method POST
                    vars_regexp {http.request.uri.path} ^/apps/{$id}/(events|batch_events|users/[^/]+/terminate_connections)$
                    vars {http.request.uri.query.auth_key} {$key}
                    query auth_signature=* auth_timestamp=* auth_version=1.0
                }
                handle @orbit_reverb_publish {
                    {$proxy}
                }
                @orbit_reverb_read {
                    method GET
                    vars_regexp {http.request.uri.path} ^/apps/{$id}/(connections|channels|channels/[^/]+|channels/[^/]+/users)$
                    vars {http.request.uri.query.auth_key} {$key}
                    query auth_signature=* auth_timestamp=* auth_version=1.0
                }
                handle @orbit_reverb_read {
                    {$proxy}
                }
                @orbit_reverb_reserved path /app /app/* /apps /apps/*
                respond @orbit_reverb_reserved 404
            }

            CADDY;
    }

    private function localUnixSite(DevelopmentSite $site): ?string
    {
        if (! is_string($site->localUnixUpstream) || $site->localUnixUpstream === '') {
            return null;
        }

        $root = $site->checkoutPath === '' ? null : "root * {$site->checkoutPath}/{$site->documentRoot}";
        $php = $site->phpVersion === null ? null : $this->phpHandler($site);
        $application = implode(PHP_EOL, array_filter([$root, $php, $site->checkoutPath === '' ? null : 'file_server']));

        return <<<CADDY
            http://{$site->localUnixUpstream} {
                bind {$site->localUnixUpstream}
                {$application}
            }
            CADDY;
    }

    /**
     * The site of a container clone's development servers on Caddy's development listener, where the Node's nftables table
     * redirects every connection to a port of the development range. It proxies to the port the client named in the container,
     * which Caddy reads from `Host`. Caddy keeps that `Host`, which Vite checks, and passes WebSocket upgrades, so hot reload works.
     * A port where nothing listens answers 502; a `Host` without a port in the range answers 404.
     */
    public function renderDevPorts(DevelopmentSite $site, string $bind): string
    {
        $listener = InstanceDevPorts::Listener;
        $first = InstanceDevPorts::First;
        $last = InstanceDevPorts::Last;

        return <<<CADDY
            https://{$site->domain}:{$listener} {
                bind {$bind}
                tls {$site->certificateDirectory()}/cert.pem {$site->certificateDirectory()}/key.pem
                @orbit_dev_port expression `string({http.request.port}).matches('^[0-9]+$') && int({http.request.port}) >= {$first} && int({http.request.port}) <= {$last}`
                handle @orbit_dev_port {
                    reverse_proxy {$site->devUpstream}:{http.request.port} {
                        flush_interval -1
                        transport http {
                            read_timeout 1h
                        }
                    }
                }
                respond "Orbit development port refused" 404
            }
            CADDY.PHP_EOL;
    }

    private function handler(DevelopmentSite $site): string
    {
        if ($site->unavailable) {
            return <<<'CADDY'
                header Cache-Control "no-store"
                header Content-Type "text/plain; charset=utf-8"
                respond "Orbit Route unavailable\n" 503
                CADDY;
        }

        if ($site->isAnalyticsTracking()) {
            return $this->analyticsTrackingHandler($site);
        }

        if ($site->isServiceHttpProxy()) {
            $upstream = $site->localHttpUpstream;
            $host = '';
            $tls = '';
            if ($site->managedTlsUpstream !== null) {
                $upstream = 'https://'.$site->managedTlsUpstream;
                $host = "\n    header_up Host {$site->domain}";
                $root = self::ORBIT_ROOT_CA_PATH;
                $tls = "\n        tls_server_name {$site->domain}\n        tls_trusted_ca_certs {$root}\n        versions 1.1";
            }

            $application = <<<CADDY
                reverse_proxy {$upstream} {{$host}
                    flush_interval -1
                    transport http {{$tls}
                        read_timeout 1h
                        response_header_timeout 1h
                    }
                }
                CADDY;

            $handlers = $site->viteUpstream === null ? $application : $this->withDevelopmentServer($application, $site->viteUpstream);
            $asleep = $this->containerWake($site);

            // The site template indents only the first line, so the proxy keeps its site indentation after the check.
            return $asleep === null ? $handlers : $asleep.PHP_EOL.'    '.$handlers;
        }

        if ($site->isProxy()) {
            $upstreams = implode(' ', array_map(
                static fn (string $address): string => str_starts_with($address, 'unix/')
                    ? $address
                    : "https://{$address}",
                $site->proxyAddresses(),
            ));
            $identity = $site->preserveForwardedIdentity
                ? <<<CADDY

                    header_up X-Forwarded-Proto https
                    header_up X-Forwarded-For {remote_host}
                    header_up X-Forwarded-Host {$site->domain}
                CADDY
                : '';
            $root = self::ORBIT_ROOT_CA_PATH;
            $hasRemoteHttps = array_any(
                $site->proxyAddresses(),
                static fn (string $address): bool => ! str_starts_with($address, 'unix/'),
            );
            $trust = ! $site->upstreamSystemRoots && ($site->publicListener || $site->preserveForwardedIdentity || $hasRemoteHttps)
                ? <<<CADDY

                        tls_trusted_ca_certs {$root}
                CADDY
                : '';
            $pool = count($site->proxyAddresses()) > 1
                ? <<<'CADDY'

                    lb_policy round_robin
                    lb_retries 0
                    fail_duration 10s
                CADDY
                : '';
            $unavailable = count($site->proxyAddresses()) > 1
                ? <<<'CADDY'

                handle_errors {
                    @orbit_unavailable `{err.status_code} == 502`
                    handle @orbit_unavailable {
                        header Cache-Control "no-store"
                        header Content-Type "text/plain; charset=utf-8"
                        respond "Orbit Route unavailable\n" 503
                    }
                }
                CADDY
                : '';

            return <<<CADDY
                reverse_proxy {$upstreams} {
                    header_up Host {$site->domain}{$identity}{$pool}
                    transport http {
                        tls_server_name {$site->domain}{$trust}
                    }
                }{$unavailable}
                CADDY;
        }

        $application = implode(PHP_EOL, array_filter([
            "root * {$site->checkoutPath}/{$site->documentRoot}",
            'encode zstd gzip',
            $site->phpVersion === null ? null : $this->phpHandler($site),
            'file_server',
        ]));

        if ($site->environment === 'production') {
            return $application;
        }

        $handlers = $this->withDevelopmentServer($application, $site->vitePort === null ? null : DevelopmentServerEndpoint::upstream($site->vitePort));
        $wake = $this->hibernationWake($site);

        return $wake === null ? $handlers : $wake.PHP_EOL.$handlers;
    }

    /**
     * Plausible counts a visitor by the forwarded client address. A public listener already sees the
     * visitor; a Router behind a separate Ingress trusts that Ingress alone to name the visitor.
     */
    private function analyticsTrackingHandler(DevelopmentSite $site): string
    {
        $upstream = "reverse_proxy http://{$site->analyticsUpstream}";
        $proxy = $site->analyticsTrustedProxies === []
            ? ["    {$upstream}"]
            : [
                "    {$upstream} {",
                '        trusted_proxies '.implode(' ', $site->analyticsTrustedProxies),
                '    }',
            ];

        // The site template indents the first line only, so the following lines carry the site indentation.
        return implode(PHP_EOL.'    ', [
            'handle /js/* {',
            ...$proxy,
            '}',
            'handle /api/event {',
            ...$proxy,
            '}',
            'respond 404',
        ]);
    }

    private function hibernationWake(DevelopmentSite $site): ?string
    {
        if (preg_match('/\Aapp-instance-([1-9][0-9]*)\z/D', $site->scope, $matches) !== 1) {
            return null;
        }

        $id = $matches[1];
        $key = RuntimeHibernation::key((int) $id);
        $log = RuntimeHibernation::accessLogPath($key);
        $host = parse_url($this->gatewayOrigin, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $markers = RuntimeHibernation::MarkerDirectory;
        $marker = '/'.$key.'.awake';
        $probeHeader = RuntimeHibernation::ProbeHeader;
        $activation = $this->activation((int) $id, $host);

        // A probe (`orbit profile --instance`) measures this site without disturbing it: it must
        // not reach forward_auth, which would start the Processes hibernation halted, and it must
        // not reach the access log, whose mtime is the activity that decides when they halt
        // again. Every other request behaves exactly as before.
        return <<<CADDY
            @orbit_probe header {$probeHeader} 1
            log_skip @orbit_probe
            @orbit_asleep {
                not header {$probeHeader} 1
                not file {
                    root {$markers}
                    try_files {$marker}
                }
            }
            handle @orbit_asleep {
                {$activation}
            }
            log {
                output file {$log}
            }
            CADDY;
    }

    /**
     * A sleeping development container's site (docs/reference/development-containers.md#sleep-and-wake). The `file` matcher is
     * a stat of the marker on the Node, evaluated in the request matcher: while the marker is missing it is false, and the request
     * goes straight to the proxy below without a call to the Gateway. A probe never wakes the Instance.
     */
    private function containerWake(DevelopmentSite $site): ?string
    {
        if ($site->asleepMarker === null || preg_match('/\Aapp-instance-([1-9][0-9]*)\z/D', $site->scope, $matches) !== 1) {
            return null;
        }
        $host = parse_url($this->gatewayOrigin, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }
        $directory = dirname($site->asleepMarker);
        $marker = '/'.basename($site->asleepMarker);
        $probeHeader = RuntimeHibernation::ProbeHeader;
        $activation = $this->activation((int) $matches[1], $host);
        $log = RuntimeHibernation::accessLogPath(RuntimeHibernation::key((int) $matches[1]));

        // The access log's mtime is the HTTP activity of the idle sleep. Caddy creates the file when it loads the site, so a new
        // site starts a full idle window. A probe and a request that meets the asleep marker write nothing: the wake that such a
        // request starts counts by itself. One roll of 1 MiB keeps each Instance's log near 1 MiB.
        return <<<CADDY
            @orbit_probe header {$probeHeader} 1
            log_skip @orbit_probe
            log_skip @orbit_asleep
            log {
                output file {$log} {
                    roll_size 1MiB
                    roll_keep 1
                }
            }
            @orbit_asleep {
                not header {$probeHeader} 1
                file {
                    root {$directory}
                    try_files {$marker}
                }
            }
            handle @orbit_asleep {
                {$activation}
            }
            CADDY;
    }

    /** The runtime activation call of a sleeping Instance: the Gateway answers with the progress or failure page. */
    private function activation(int $instanceId, string $host): string
    {
        $uri = '/api/v1/runtime-activations/app-instance/'.$instanceId;
        $root = self::ORBIT_ROOT_CA_PATH;

        return <<<CADDY
            forward_auth {$this->gatewayOrigin} {
                    uri {$uri}
                    header_up Host {$host}
                    transport http {
                        tls_trusted_ca_certs {$root}
                        tls_server_name {$host}
                    }
                }
            CADDY;
    }

    private function withDevelopmentServer(string $applicationHandler, ?string $upstream): string
    {
        $vite = '';

        if ($upstream !== null) {
            $path = DevelopmentServerEndpoint::PATH;
            $vite = <<<CADDY
                @orbit_vite {
                    path {$path} {$path}/*
                }
                handle @orbit_vite {
                    reverse_proxy {$upstream}
                }

                CADDY;
        }

        return $vite.<<<CADDY
            handle {
                {$applicationHandler}
            }
            CADDY;
    }

    private function phpHandler(DevelopmentSite $site): string
    {
        if ($site->environment !== 'production' && ! str_ends_with($site->checkoutPath, '/current')) {
            return "php_fastcgi unix/{$site->socketPath()}";
        }

        return <<<CADDY
            php_fastcgi unix/{$site->socketPath()} {
                resolve_root_symlink
            }
            CADDY;
    }
}
