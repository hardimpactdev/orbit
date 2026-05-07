<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;

final readonly class ProxyRouteProbe
{
    private const array OwnerTypes = ['app', 'workspace', 'gateway', 'tool', 'custom'];

    private const array Kinds = ['app', 'workspace', 'internal', 'proxy', 'redirect'];

    public function __construct(
        private ?RemoteShell $remoteShell = null,
    ) {}

    public function key(): string
    {
        return 'proxy';
    }

    public function label(): string
    {
        return 'Proxy';
    }

    public function introspect(ProxyRoute $route): ProbeSnapshot
    {
        $route->loadMissing('node');

        if (! $route->node instanceof Node || $route->domain === '') {
            return new ProbeSnapshot([]);
        }

        $script = <<<'BASH'
set -euo pipefail
domain="$ORBIT_PROXY_DOMAIN"
path="/etc/caddy/sites/${domain}.caddy"
exists=0
hash=""
cert=""
key=""
cert_exists=0
key_exists=0

if [ -f "$path" ]; then
    exists=1
    hash=$(sha256sum "$path" | awk '{print $1}')
    cert=$(awk '$1 == "tls" && $2 != "internal" {print $2; exit}' "$path")
    key=$(awk '$1 == "tls" && $2 != "internal" {print $3; exit}' "$path")
    [ -n "$cert" ] && [ -f "$cert" ] && cert_exists=1
    [ -n "$key" ] && [ -f "$key" ] && key_exists=1
fi

printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$exists" "$hash" "$cert" "$key" "$cert_exists" "$key_exists"
BASH;

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($route->node, $script, [
            'throw' => true,
            'env' => [
                'ORBIT_PROXY_DOMAIN' => $route->domain,
            ],
        ]);

        $parts = explode("\t", trim($result->stdout), 6);

        if (count($parts) !== 6) {
            return new ProbeSnapshot([]);
        }

        [$exists, $hash, $cert, $key, $certExists, $keyExists] = $parts;

        return new ProbeSnapshot([
            $route->domain => [
                'route_exists' => $exists === '1',
                'route_hash' => $hash,
                'cert_path' => $cert,
                'key_path' => $key,
                'cert_exists' => $certExists === '1',
                'key_exists' => $keyExists === '1',
            ],
        ]);
    }

    public function introspectNode(Node $node): ProbeSnapshot
    {
        $script = <<<'BASH'
set -euo pipefail
if [ ! -d /etc/caddy/sites ]; then
    exit 0
fi
for f in /etc/caddy/sites/*.caddy; do
    [ -e "$f" ] || continue
    name=$(basename "$f" .caddy)
    hash=$(sha256sum "$f" | awk '{print $1}')
    cert=$(awk '$1 == "tls" && $2 != "internal" {print $2; exit}' "$f")
    key=$(awk '$1 == "tls" && $2 != "internal" {print $3; exit}' "$f")
    cert_exists=0; key_exists=0
    [ -n "$cert" ] && [ -f "$cert" ] && cert_exists=1
    [ -n "$key" ] && [ -f "$key" ] && key_exists=1
    printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$name" "$hash" "$cert" "$key" "$cert_exists" "$key_exists"
done
BASH;

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($node, $script, ['throw' => true]);
        $items = [];

        foreach (explode("\n", rtrim($result->stdout, "\n\r")) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 6);

            if (count($parts) !== 6) {
                continue;
            }

            [$name, $hash, $cert, $key, $certExists, $keyExists] = $parts;
            $items[$name] = [
                'route_exists' => true,
                'route_hash' => $hash,
                'cert_path' => $cert,
                'key_path' => $key,
                'cert_exists' => $certExists === '1',
                'key_exists' => $keyExists === '1',
            ];
        }

        return new ProbeSnapshot($items);
    }

    public function snapshotForAdopt(Node $node): ProbeSnapshot
    {
        $script = <<<'BASH'
set -euo pipefail
if [ ! -d /etc/caddy/sites ]; then
    exit 0
fi
for f in /etc/caddy/sites/*.caddy; do
    [ -e "$f" ] || continue
    name=$(basename "$f" .caddy)
    vhost_hash=$(sha256sum "$f" | awk '{print $1}')
    body_b64=$(base64 -w0 "$f" 2>/dev/null || base64 "$f" | tr -d '\n')
    printf '%s\t%s\t%s\n' "$name" "$vhost_hash" "$body_b64"
done
BASH;

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($node, $script, ['throw' => true]);
        $items = [];

        foreach (explode("\n", rtrim($result->stdout, "\n\r")) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 3);

            if (count($parts) !== 3) {
                continue;
            }

            [$name, $hash, $bodyB64] = $parts;
            $body = base64_decode($bodyB64, true);

            if ($body === false) {
                continue;
            }

            $items[$name] = [
                'hash' => $hash,
                'body' => $body,
            ];
        }

        return new ProbeSnapshot($items);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(ProxyRoute $route, ProbeSnapshot $snapshot): array
    {
        return [
            ...$this->checkRecordCompleteness($route),
            ...$this->checkOwnerEligibility($route),
            ...$this->checkNodeEligibility($route),
            ...$this->checkCustomDomainConflict($route),
            ...$this->checkBackendReality($route, $snapshot),
            ...$this->checkTlsReality($route, $snapshot),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    public function diffNode(Node $node, ProbeSnapshot $snapshot): array
    {
        $drift = [];
        $dbRoutes = ProxyRoute::query()->where('node_id', $node->id)->get();
        $observedDomains = $snapshot->keys();

        foreach ($dbRoutes as $route) {
            $routeDrift = $this->diff($route, $snapshot);

            if (! in_array($route->domain, $observedDomains, true)) {
                $hasBackendDrift = collect($routeDrift)->contains(
                    fn (DriftEntry $entry): bool => $entry->key === 'proxy.route_missing' || $entry->key === 'proxy.route_mismatch'
                );

                if (! $hasBackendDrift) {
                    $routeDrift[] = new DriftEntry(
                        family: $this->key(),
                        key: 'proxy.route_missing',
                        kind: DriftKind::Missing,
                        summary: "Proxy backend route {$route->domain} is missing on the serving node.",
                    );
                }
            }

            $drift = array_merge($drift, $routeDrift);
        }

        $dbDomains = $dbRoutes->pluck('domain')->all();

        foreach ($snapshot->keys() as $domain) {
            $domain = (string) $domain;

            if (in_array($domain, $dbDomains, true)) {
                continue;
            }

            $drift[] = new DriftEntry(
                family: $this->key(),
                key: $domain,
                kind: DriftKind::Extra,
                summary: "Proxy route '{$domain}' exists on node but not in gateway registry.",
            );
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(ProxyRoute $route): array
    {
        if (
            ! is_string($route->domain)
            || $route->domain === ''
            || ! is_int($route->node_id)
            || ! in_array($route->owner_type, self::OwnerTypes, true)
            || ! in_array($route->kind, self::Kinds, true)
            || ! is_string($route->source_hash)
            || $route->source_hash === ''
            || ! $this->hasTargetShape($route)
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "Proxy route record for {$route->domain} is missing required fields.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkOwnerEligibility(ProxyRoute $route): array
    {
        $route->loadMissing(['app', 'workspace']);

        if ($route->owner_type === 'app' && ! $route->app instanceof App) {
            return [$this->ownerInvalid($route, 'app')];
        }

        if ($route->owner_type === 'workspace' && ! $route->workspace instanceof Workspace) {
            return [$this->ownerInvalid($route, 'workspace')];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkNodeEligibility(ProxyRoute $route): array
    {
        $route->loadMissing('node');

        if (! $route->node instanceof Node) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Proxy route {$route->domain} points at a missing serving node.",
                ),
            ];
        }

        if ($route->node->status !== 'active' || ! in_array($route->node->role, ['gateway', 'app'], true)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Proxy route {$route->domain} is served by node {$route->node->name}, which is not an active gateway or app node.",
                    detail: [
                        'node' => $route->node->name,
                        'role' => $route->node->role,
                        'status' => $route->node->status,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkCustomDomainConflict(ProxyRoute $route): array
    {
        if ($route->owner_type !== 'custom') {
            return [];
        }

        $app = App::query()
            ->where('domain', $route->domain)
            ->first();

        if ($app instanceof App) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.domain_conflict',
                    kind: DriftKind::Divergent,
                    summary: "Custom proxy route {$route->domain} conflicts with app {$app->name}.",
                    detail: [
                        'domain' => $route->domain,
                        'owner_type' => 'app',
                        'owner_name' => $app->name,
                    ],
                ),
            ];
        }

        return [];
    }

    private function ownerInvalid(ProxyRoute $route, string $ownerType): DriftEntry
    {
        return new DriftEntry(
            family: $this->key(),
            key: 'proxy.owner_invalid',
            kind: DriftKind::Divergent,
            summary: "Proxy route {$route->domain} points at a missing {$ownerType} owner.",
            detail: [
                'owner_type' => $ownerType,
            ],
        );
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkBackendReality(ProxyRoute $route, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($route->domain);

        if ($observed === null) {
            return [];
        }

        if (($observed['route_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.route_missing',
                    kind: DriftKind::Missing,
                    summary: "Proxy backend route {$route->domain} is missing on the serving node.",
                ),
            ];
        }

        if (($observed['route_hash'] ?? null) !== $route->source_hash) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.route_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Proxy backend route {$route->domain} differs from gateway proxy intent.",
                    detail: [
                        'expected_hash' => $route->source_hash,
                        'observed_hash' => $observed['route_hash'] ?? null,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkTlsReality(ProxyRoute $route, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($route->domain);

        if ($observed === null || ! $this->expectsOrbitTls($route)) {
            return [];
        }

        if (($observed['route_exists'] ?? null) === false) {
            return [];
        }

        if (($observed['cert_exists'] ?? null) === false || ($observed['key_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.tls_missing',
                    kind: DriftKind::Missing,
                    summary: "Proxy route {$route->domain} is missing Orbit-managed TLS material.",
                ),
            ];
        }

        $expected = $this->expectedTlsPaths($route);

        if (($observed['cert_path'] ?? null) !== $expected['cert'] || ($observed['key_path'] ?? null) !== $expected['key']) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.tls_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Proxy route {$route->domain} TLS material does not match gateway proxy intent.",
                    detail: [
                        'expected' => $expected,
                        'observed' => [
                            'cert' => $observed['cert_path'] ?? null,
                            'key' => $observed['key_path'] ?? null,
                        ],
                    ],
                ),
            ];
        }

        return [];
    }

    private function expectsOrbitTls(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];
        $managedBy = $config['tls']['managed_by'] ?? $config['tls_managed_by'] ?? 'orbit';

        return $managedBy === 'orbit';
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function expectedTlsPaths(ProxyRoute $route): array
    {
        $config = is_array($route->config) ? $route->config : [];
        $cert = $config['tls']['cert_path'] ?? null;
        $key = $config['tls']['key_path'] ?? null;

        return [
            'cert' => is_string($cert) && $cert !== '' ? $cert : "/etc/orbit/certs/{$route->domain}.crt",
            'key' => is_string($key) && $key !== '' ? $key : "/etc/orbit/certs/{$route->domain}.key",
        ];
    }

    private function hasTargetShape(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];

        if ($route->kind === 'redirect') {
            $target = $config['target']['value'] ?? $config['redirect'] ?? $config['redirect_url'] ?? null;
            $code = $config['code'] ?? $config['redirect_code'] ?? null;

            return is_string($target)
                && $target !== ''
                && is_int($code);
        }

        if ($route->kind === 'proxy') {
            $target = $config['target']['value'] ?? $config['upstream'] ?? $config['target'] ?? null;

            return is_string($target) && $target !== '';
        }

        if ($route->kind === 'app') {
            return is_int($route->app_id);
        }

        if ($route->kind === 'workspace') {
            return is_int($route->workspace_id);
        }

        return true;
    }
}
