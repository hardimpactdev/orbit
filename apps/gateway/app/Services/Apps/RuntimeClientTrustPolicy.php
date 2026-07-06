<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Nodes\NodeRoleName;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\NodeHostPaths;

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
    ) {}

    /**
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    public function mountsForApp(App $app): array
    {
        if (! $this->appliesToApp($app)) {
            return [];
        }

        $app->loadMissing('node');

        if (! $app->node instanceof Node) {
            return [];
        }

        return [
            [
                'source' => $this->nodeHostPaths->runtimeTrustPoolPath($app->node),
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
        $workspace->loadMissing('app');
        $app = $workspace->app;

        return $app instanceof App ? $this->mountsForApp($app) : [];
    }

    /**
     * @return array<string, string>
     */
    public function phpIniForApp(App $app): array
    {
        if (! $this->appliesToApp($app)) {
            return [];
        }

        return self::PHP_INI;
    }

    /**
     * @return array<string, string>
     */
    public function phpIniForWorkspace(Workspace $workspace): array
    {
        $workspace->loadMissing('app');
        $app = $workspace->app;

        return $app instanceof App ? $this->phpIniForApp($app) : [];
    }

    /**
     * @return array<string, string>
     */
    public function environmentForApp(App $app): array
    {
        if (! $this->appliesToApp($app)) {
            return [];
        }

        return self::ENVIRONMENT;
    }

    /**
     * @return array<string, string>
     */
    public function environmentForWorkspace(Workspace $workspace): array
    {
        $workspace->loadMissing('app');
        $app = $workspace->app;

        return $app instanceof App ? $this->environmentForApp($app) : [];
    }

    private function appliesToApp(App $app): bool
    {
        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return false;
        }

        if ($app->environment === 'production') {
            return false;
        }

        $app->loadMissing('node.roleAssignments');

        return $app->node?->hasActiveRole(NodeRoleName::AppDevelopment->value) === true;
    }
}
