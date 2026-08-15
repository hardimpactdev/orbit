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

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class RuntimeClientTrustPolicy
{
    private const array PHP_INI = [
        'openssl.cafile' => AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath,
        'curl.cainfo' => AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath,
    ];

    private const array ENVIRONMENT = [
        'SSL_CERT_FILE' => AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath,
        'CURL_CA_BUNDLE' => AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath,
    ];

    public function __construct(
        private NodeHostPaths $nodeHostPaths = new NodeHostPaths,
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    /**
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    public function mountsForApp(App $app, ?Instance $instance = null): array
    {
        if (! $this->appliesToApp($app, $instance)) {
            return [];
        }

        $node = $this->placement->runtimeNode($app, $instance);

        if (! $node instanceof Node) {
            return [];
        }

        return [
            [
                'source' => $this->nodeHostPaths->runtimeTrustPoolPath($node),
                'target' => AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath,
                'read_only' => true,
            ],
        ];
    }

    /**
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    public function mountsForWorkspace(Workspace $workspace): array
    {
        $workspace->loadMissing(['app', 'app.instances', 'instance']);
        $app = $workspace->app;

        if (! $app instanceof App) {
            return [];
        }

        $node = $this->placement->nodeForWorkspace($workspace);

        if (! $node instanceof Node || ! $this->appliesTo($app, $node)) {
            return [];
        }

        return [
            [
                'source' => $this->nodeHostPaths->runtimeTrustPoolPath($node),
                'target' => AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath,
                'read_only' => true,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function phpIniForApp(App $app, ?Instance $instance = null): array
    {
        if (! $this->appliesToApp($app, $instance)) {
            return [];
        }

        return self::PHP_INI;
    }

    /**
     * @return array<string, string>
     */
    public function phpIniForWorkspace(Workspace $workspace): array
    {
        $workspace->loadMissing(['app', 'app.instances', 'instance']);
        $app = $workspace->app;

        if (! $app instanceof App) {
            return [];
        }

        $node = $this->placement->nodeForWorkspace($workspace);

        return $node instanceof Node && $this->appliesTo($app, $node) ? self::PHP_INI : [];
    }

    /**
     * @return array<string, string>
     */
    public function environmentForApp(App $app, ?Instance $instance = null): array
    {
        if (! $this->appliesToApp($app, $instance)) {
            return [];
        }

        return self::ENVIRONMENT;
    }

    /**
     * @return array<string, string>
     */
    public function environmentForWorkspace(Workspace $workspace): array
    {
        $workspace->loadMissing(['app', 'app.instances', 'instance']);
        $app = $workspace->app;

        if (! $app instanceof App) {
            return [];
        }

        $node = $this->placement->nodeForWorkspace($workspace);

        return $node instanceof Node && $this->appliesTo($app, $node) ? self::ENVIRONMENT : [];
    }

    private function appliesToApp(App $app, ?Instance $instance = null): bool
    {
        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return false;
        }

        if ($this->placement->runtimeEnvironment($app, $instance) === 'production') {
            return false;
        }

        $node = $this->placement->runtimeNode($app, $instance);

        return $node instanceof Node && $this->appliesTo($app, $node, $instance);
    }

    private function appliesTo(App $app, Node $node, ?Instance $instance = null): bool
    {
        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return false;
        }

        if ($this->placement->runtimeEnvironment($app, $instance) === 'production') {
            return false;
        }

        $node->loadMissing('roleAssignments');

        return $node->hasActiveRole(NodeRoleName::AppDevelopment->value);
    }
}
