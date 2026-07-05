<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Contracts\SiteCertificateInstaller;
use App\Data\Doctor\DriftEntry;
use App\Enums\Apps\AppRuntimeArtifactRemovalOutcome;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Services\Processes\EnsureFrankenPhpRuntimeProcess;
use RuntimeException;

final readonly class AppsFixer
{
    public function __construct(
        private AppRuntimeContainerRenderer $appRuntimeContainerRenderer,
        private AppRuntimeContainerManager $appRuntimeContainerManager,
        private AppRuntimeUser $appRuntimeUser,
        private EnsureFrankenPhpRuntimeProcess $ensureFrankenPhpRuntimeProcess,
        private SiteCertificateInstaller $siteCertificateInstaller,
        private AppDevelopmentInnerTlsPolicy $innerTlsPolicy = new AppDevelopmentInnerTlsPolicy,
        private ?RemoteAppSecurityRepair $securityRepair = null,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fix(App $app, DriftEntry $entry): ?array
    {
        $app->loadMissing('node');
        $node = $app->node;

        if (! $node instanceof Node) {
            return null;
        }

        return match ($entry->key) {
            'app.runtime_container_missing',
            'app.runtime_container_mismatch',
            'app.security.runtime_container_isolation',
                => $this->reapplyRuntimeContainer($app, $node, $entry),
            'app.runtime_config_missing', 'app.runtime_config_mismatch' => $this->reapplyRuntimeConfig(
                $app,
                $node,
                $entry,
            ),
            'app.security.system_user', 'app.security.fs_permissions' => $this->reapplyAppSecurity($app, $node, $entry),
            default => null,
        };
    }

    /**
     * Remove an Orbit-owned app runtime container whose encoded app identity
     * no longer maps to an active app record on the node.
     *
     * @return array<string, mixed>
     */
    public function removeExtra(Node $node, string $appSlug): array
    {
        $outcome = $this->appRuntimeContainerManager->remove($node, $appSlug);

        if ($outcome === AppRuntimeArtifactRemovalOutcome::FailedRemaining) {
            throw new RuntimeException("Failed to remove orbit-app-{$appSlug} container on {$node->name}.");
        }

        return [
            'family' => 'app',
            'node' => $node->name,
            'code' => 'app.runtime_container_extra',
            'key' => 'app.runtime_container_extra',
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Removed extra app runtime container for {$appSlug}.",
            'details' => [
                'app' => $appSlug,
                'container' => "orbit-app-{$appSlug}",
                'outcome' => $outcome->value,
            ],
        ];
    }

    /**
     * Remove an orphan managed runtime config file (/etc/orbit/apps/<slug>.ini)
     * whose encoded app identity no longer maps to an active app record on
     * the node.
     *
     * @return array<string, mixed>
     */
    public function removeRuntimeConfigExtra(Node $node, string $appSlug): array
    {
        $outcome = $this->appRuntimeContainerManager->removeRuntimeConfigFile($node, $appSlug);

        if ($outcome === AppRuntimeArtifactRemovalOutcome::FailedRemaining) {
            throw new RuntimeException("Failed to remove managed runtime config for '{$appSlug}' on {$node->name}.");
        }

        return [
            'family' => 'app',
            'node' => $node->name,
            'code' => 'app.runtime_config_extra',
            'key' => 'app.runtime_config_extra',
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Removed extra app runtime config for {$appSlug}.",
            'details' => [
                'app' => $appSlug,
                'path' => $this->appRuntimeContainerManager->runtimeConfigPath($appSlug),
                'outcome' => $outcome->value,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reapplyRuntimeContainer(App $app, Node $node, DriftEntry $entry): ?array
    {
        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return null;
        }

        $this->ensureFrankenPhpRuntimeProcess->forApp($app);
        $this->ensureRuntimeTlsMaterial($app, $node);
        $container = $this->appRuntimeContainerRenderer->render($app);
        $this->appRuntimeContainerManager->apply($node, $container);

        return [
            'family' => 'app',
            'node' => $node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Re-applied app runtime container for {$app->name}.",
            'details' => [
                'app' => $app->name,
                'container' => $container->name(),
            ],
        ];
    }

    private function ensureRuntimeTlsMaterial(App $app, Node $node): void
    {
        if (! $this->innerTlsPolicy->appliesToApp($app)) {
            return;
        }

        $this->siteCertificateInstaller->ensureFor(
            $node,
            $this->innerTlsPolicy->appRouteDomain($app),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reapplyRuntimeConfig(App $app, Node $node, DriftEntry $entry): ?array
    {
        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return null;
        }

        $this->ensureFrankenPhpRuntimeProcess->forApp($app);
        $container = $this->appRuntimeContainerRenderer->render($app);
        $this->appRuntimeContainerManager->writeRuntimeConfigFile($node, $container);

        return [
            'family' => 'app',
            'node' => $node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Re-applied managed runtime config for {$app->name}.",
            'details' => [
                'app' => $app->name,
                'path' => $this->appRuntimeContainerManager->runtimeConfigPath($app->name),
            ],
        ];
    }

    /**
     * Restore production app security baseline: ensure the configured runtime
     * user exists and the app path ownership/permissions match the policy.
     *
     * @return array<string, mixed>
     */
    private function reapplyAppSecurity(App $app, Node $node, DriftEntry $entry): array
    {
        $user = $this->appRuntimeUser->forApp($app);
        $home = $user === 'root' ? '/root' : "/home/{$user}";
        $appPath = rtrim($app->path, '/');

        $this->securityRepair()->repair(
            node: $node,
            user: $user,
            home: $home,
            path: $appPath,
        );

        return [
            'family' => 'app',
            'node' => $node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Re-applied app runtime user and filesystem policy for {$app->name}.",
            'details' => [
                'app' => $app->name,
                'runtime_user' => $user,
                'path' => $app->path,
            ],
        ];
    }

    private function securityRepair(): RemoteAppSecurityRepair
    {
        return $this->securityRepair ?? app(RemoteAppSecurityRepair::class);
    }
}
