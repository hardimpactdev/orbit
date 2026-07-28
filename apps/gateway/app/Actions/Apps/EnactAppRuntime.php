<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\SiteCertificateInstaller;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Apps\AppRuntimeContainerApplyException;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Apps\AppRuntimeImageUnavailableException;
use App\Services\Apps\AppRuntimeUserUnavailableException;
use App\Services\Processes\EnsureFrankenPhpRuntimeProcess;
use App\Services\Workspaces\WorkspacePlacement;
use RuntimeException;
use Throwable;

final readonly class EnactAppRuntime
{
    public function __construct(
        private EnsureAppProxyRoute $ensureAppProxyRoute,
        private EnsureAppProcessRuntimeUnits $ensureAppProcessRuntimeUnits,
        private AppRuntimeContainerRenderer $appRuntimeContainerRenderer,
        private AppRuntimeContainerManager $appRuntimeContainerManager,
        private EnsureFrankenPhpRuntimeProcess $ensureFrankenPhpRuntimeProcess,
        private SiteCertificateInstaller $siteCertificateInstaller,
        private WorkspacePlacement $placement,
        private AppDevelopmentInnerTlsPolicy $innerTlsPolicy = new AppDevelopmentInnerTlsPolicy,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function handle(Project $app): array
    {
        $app->loadMissing('instances');

        if ($app->instances->isEmpty()) {
            throw new RuntimeException("App '{$app->name}' has no concrete instance.");
        }

        $warnings = [];

        foreach ($app->instances as $instance) {
            if (! $instance instanceof AppInstance || $instance->driver !== AppInstanceDriver::Orbit) {
                continue;
            }

            $node = $this->placement->nodeForInstance($instance);

            if (! $node instanceof Node) {
                throw new RuntimeException("Instance '{$app->name}.{$instance->name}' has no owning node.");
            }

            $runtimeApp = $this->appRuntimeContainerRenderer->runtimeAppForInstance($app, $instance);

            if ($runtimeApp->runtimeKind() === AppRuntimeKind::Php) {
                try {
                    $this->ensureFrankenPhpRuntimeProcess->forApp($app, $instance);
                    $this->ensureRuntimeTlsMaterial($runtimeApp, $node);
                    $container = $this->appRuntimeContainerRenderer->renderForInstance($app, $instance);
                    $this->appRuntimeContainerManager->apply($node, $container);
                } catch (AppRuntimeImageUnavailableException $exception) {
                    $warnings[] = $this->phpVersionUnavailableWarning($runtimeApp, $exception);
                } catch (AppRuntimeUserUnavailableException $exception) {
                    $warnings[] = $this->runtimeUserUnavailableWarning($runtimeApp, $exception);
                } catch (AppRuntimeContainerApplyException $exception) {
                    $warnings[] = $this->runtimeContainerWarning(
                        $runtimeApp,
                        $exception->hadExistingContainer,
                        $exception,
                    );
                } catch (Throwable $exception) {
                    $warnings[] = $this->runtimeContainerWarning(
                        $runtimeApp,
                        hadExistingContainer: false,
                        exception: $exception,
                    );
                }
            }

            $warnings = [
                ...$warnings,
                ...$this->ensureAppProcessRuntimeUnits->handle($app, $instance),
                ...$this->ensureAppProxyRoute->handle($runtimeApp, $instance),
            ];
        }

        return $warnings;
    }

    private function ensureRuntimeTlsMaterial(Project $app, Node $owningNode): void
    {
        if (! $this->innerTlsPolicy->appliesToApp($app)) {
            return;
        }

        $this->siteCertificateInstaller->ensureFor(
            $owningNode,
            $this->innerTlsPolicy->appRouteDomain($app),
        );
    }

    /**
     * @return array<string, string>
     */
    private function runtimeContainerWarning(Project $app, bool $hadExistingContainer, Throwable $exception): array
    {
        $code = $hadExistingContainer
            ? 'process.runtime_unit_mismatch'
            : 'process.runtime_unit_missing';

        $action = $hadExistingContainer ? 'recreated' : 'installed';

        return [
            'code' => $code,
            'family' => 'process',
            'message' => "FrankenPHP runtime container for '{$app->name}' could not be {$action} on '{$app->node?->name}': {$exception->getMessage()}",
            'next_command' => 'doctor --family=process --restore',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function phpVersionUnavailableWarning(Project $app, AppRuntimeImageUnavailableException $exception): array
    {
        return [
            'code' => 'instance.php_version_unavailable',
            'family' => 'instance',
            'message' => "PHP {$app->php_version} runtime image '{$exception->image}' is not available on node '{$app->node?->name}'. Make the image available, then run doctor.",
            'next_command' => 'doctor --family=instance --restore',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function runtimeUserUnavailableWarning(Project $app, AppRuntimeUserUnavailableException $exception): array
    {
        return [
            'code' => 'instance.security.system_user',
            'family' => 'instance',
            'message' => "Production runtime user '{$exception->runtimeUser}' for instance '{$app->name}' is missing on '{$app->node?->name}': {$exception->getMessage()}",
            'next_command' => 'doctor --family=instance --restore',
        ];
    }
}
