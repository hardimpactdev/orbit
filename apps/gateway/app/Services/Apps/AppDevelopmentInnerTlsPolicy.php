<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Nodes\NodeRoleName;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Workspaces\WorkspacePlacement;
use RuntimeException;

final readonly class AppDevelopmentInnerTlsPolicy
{
    public const int InternalTlsPort = 8443;

    public const string RuntimeTlsCertContainerPath = '/etc/orbit/runtime-tls/tls.crt';

    public const string RuntimeTlsKeyContainerPath = '/etc/orbit/runtime-tls/tls.key';

    public const string RuntimeTrustPoolPath = '/etc/orbit/ca/root.crt';

    public function __construct(
        private NodeHostPaths $nodeHostPaths = new NodeHostPaths,
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    public function appliesToApp(App $app, ?Instance $instance = null): bool
    {
        $node = $this->placement->runtimeNode($app, $instance);

        return $node instanceof Node && $this->appliesToAppOnNode($app, $node, $instance);
    }

    public function appliesToAppOnNode(App $app, Node $node, ?Instance $instance = null): bool
    {
        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return false;
        }

        if ($this->placement->runtimeEnvironment($app, $instance) === 'production') {
            return false;
        }

        if (! $app->runtimeConfig()->usesInnerHttpsProxyTransport()) {
            return false;
        }

        $node->loadMissing('roleAssignments');

        return $node->hasActiveRole(NodeRoleName::AppDevelopment->value);
    }

    public function appliesToWorkspace(Workspace $workspace): bool
    {
        $workspace->loadMissing(['app.node.roleAssignments', 'app.instances', 'instance']);

        $app = $workspace->app;

        if (! $app instanceof App) {
            return false;
        }

        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return false;
        }

        $instance = $this->placement->instanceForWorkspace($workspace);

        if ($this->placement->runtimeEnvironment($app, $instance) === 'production') {
            return false;
        }

        if (! $app->runtimeConfig()->usesInnerHttpsProxyTransport()) {
            return false;
        }

        $node = $this->placement->nodeForWorkspace($workspace);

        return $node?->hasActiveRole(NodeRoleName::AppDevelopment->value) === true;
    }

    public function appRouteDomain(App $app, ?Instance $instance = null): string
    {
        $domain = $this->placement->runtimeDomain($app, $instance);

        if (is_string($domain) && $domain !== '') {
            return $domain;
        }

        $nodeTld = $this->placement->runtimeNode($app, $instance)?->tld;
        $tld = is_string($nodeTld) ? trim($nodeTld, '.') : '';

        if ($tld === '') {
            return $app->name;
        }

        return "{$app->name}.{$tld}";
    }

    public function workspaceRouteDomain(Workspace $workspace): string
    {
        $workspace->loadMissing(['app.node', 'app.instances', 'instance']);
        $app = $workspace->app;

        if (! $app instanceof App) {
            throw new RuntimeException('Workspace has no parent app.');
        }

        return $this->placement->workspaceDomain($workspace);
    }

    public function nodeHome(Node $node): string
    {
        return $this->nodeHostPaths->homeDirectory($node);
    }

    /**
     * @return array{cert: string, key: string}
     */
    public function siteCertificatePaths(Node $node, string $domain): array
    {
        $base = $this->nodeHome($node).'/.config/orbit/certs';

        return [
            'cert' => "{$base}/{$domain}.crt",
            'key' => "{$base}/{$domain}.key",
        ];
    }

    /**
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    public function runtimeTlsMounts(Node $node, string $domain): array
    {
        $paths = $this->siteCertificatePaths($node, $domain);

        return [
            [
                'source' => $paths['cert'],
                'target' => self::RuntimeTlsCertContainerPath,
                'read_only' => true,
            ],
            [
                'source' => $paths['key'],
                'target' => self::RuntimeTlsKeyContainerPath,
                'read_only' => true,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function runtimeTlsEnvironment(string $domain): array
    {
        return [
            'SERVER_NAME' => "https://{$domain}:".self::InternalTlsPort,
            'CADDY_SERVER_EXTRA_DIRECTIVES' =>
                'tls '.self::RuntimeTlsCertContainerPath.' '.self::RuntimeTlsKeyContainerPath,
        ];
    }

    /**
     * @return array{trusted_by_gateway_ca: bool, ca_path: string, server_name: string}
     */
    public function runtimeUpstreamTlsConfig(Node $node, string $domain): array
    {
        return [
            'trusted_by_gateway_ca' => true,
            'ca_path' => self::RuntimeTrustPoolPath,
            'server_name' => $domain,
        ];
    }

    public function trustPoolInstallScript(string $caPath, string $rootCertificate): string
    {
        if (preg_match('/[\x00-\x1F\x7F\s]/', $caPath) === 1 || ! str_starts_with($caPath, '/')) {
            throw new RuntimeException('The app-dev runtime TLS trust pool path must be absolute and shell-safe.');
        }

        return sprintf(
            <<<'SH'
                install -d -m 0755 %s
                printf %%s %s | base64 -d > %s
                chmod 0644 %s
                SH,
            escapeshellarg(dirname($caPath)),
            escapeshellarg(base64_encode($rootCertificate)),
            escapeshellarg($caPath),
            escapeshellarg($caPath),
        );
    }

    /**
     * @param  array<string, mixed>  $runtimeUpstreamTls
     */
    public function runtimeUpstreamTransportDirectives(array $runtimeUpstreamTls): string
    {
        if (($runtimeUpstreamTls['trusted_by_gateway_ca'] ?? null) !== true) {
            return '';
        }

        $caPath = $runtimeUpstreamTls['ca_path'] ?? null;
        $serverName = $runtimeUpstreamTls['server_name'] ?? null;

        if (! is_string($caPath) || $caPath === '' || ! is_string($serverName) || $serverName === '') {
            return '';
        }

        if (preg_match('/[\x00-\x1F\x7F\s]/', $caPath) === 1 || preg_match('/[\x00-\x1F\x7F\s]/', $serverName) === 1) {
            return '';
        }

        return (
            "        transport http {\n"
            ."            tls_trust_pool file {$caPath}\n"
            ."            tls_server_name {$serverName}\n"
            ."        }\n"
        );
    }
}
