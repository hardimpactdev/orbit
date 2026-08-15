<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\SiteCertificateInstaller;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Apps\InstanceDriver;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Apps\AppRuntimeContainerApplyException;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Apps\AppRuntimeImageUnavailableException;
use App\Services\Apps\AppRuntimeUser;
use App\Services\Apps\AppRuntimeUserUnavailableException;
use App\Services\Apps\RemoteAppSecurityRepair;
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
        private AppRuntimeUser $appRuntimeUser,
        private RemoteAppSecurityRepair $appSecurityRepair,
        private AppDevelopmentInnerTlsPolicy $innerTlsPolicy = new AppDevelopmentInnerTlsPolicy,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function handle(App $app): array
    {
        $app->loadMissing('instances');

        if ($app->instances->isEmpty()) {
            throw new RuntimeException("App '{$app->name}' has no concrete instance.");
        }

        $warnings = [];

        foreach ($app->instances as $instance) {
            if (! $instance instanceof Instance || $instance->driver !== InstanceDriver::Orbit) {
                continue;
            }

            $node = $this->placement->nodeForInstance($instance);

            if (! $node instanceof Node) {
                throw new RuntimeException("Instance '{$app->name}.{$instance->name}' has no owning node.");
            }

            $runtimeApp = $this->appRuntimeContainerRenderer->runtimeAppForInstance($app, $instance);

            if ($runtimeApp->runtimeKind() === AppRuntimeKind::Php) {
                try {
                    $this->applyRuntime($app, $runtimeApp, $instance, $node);
                } catch (AppRuntimeImageUnavailableException $exception) {
                    $warnings[] = $this->phpVersionUnavailableWarning($runtimeApp, $exception);
                } catch (AppRuntimeUserUnavailableException $exception) {
                    $warning = $this->provisionRuntimeUserThenRetry(
                        $app,
                        $runtimeApp,
                        $instance,
                        $node,
                        $exception,
                    );

                    if ($warning !== null) {
                        $warnings[] = $warning;
                    }
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

    private function applyRuntime(App $app, App $runtimeApp, Instance $instance, Node $node): void
    {
        $this->ensureFrankenPhpRuntimeProcess->forApp($app, $instance);
        $this->ensureRuntimeTlsMaterial($runtimeApp, $node);
        $this->appRuntimeContainerManager->apply(
            $node,
            $this->appRuntimeContainerRenderer->renderForInstance($app, $instance),
        );
    }

    /**
     * A production app's runtime user is implied by its `/home/<user>/app`
     * path, but app creation only makes the directory — nothing creates the
     * user before the first container apply. Provision it in place and retry
     * once, so creating a production app yields a running runtime instead of a
     * warning the operator has to chase. This runs the same idempotent repair
     * the doctor restore path uses, and only fires for production apps because
     * a development container never resolves an explicit runtime user.
     *
     * @return array<string, string>|null Warning when the app is still not converged.
     */
    private function provisionRuntimeUserThenRetry(
        App $app,
        App $runtimeApp,
        Instance $instance,
        Node $node,
        AppRuntimeUserUnavailableException $exception,
    ): ?array {
        try {
            $user = $this->appRuntimeUser->forApp($runtimeApp);

            $this->appSecurityRepair->repair(
                node: $node,
                user: $user,
                home: $this->appRuntimeUser->homeFor($user),
                path: rtrim($runtimeApp->path, '/'),
            );
        } catch (Throwable) {
            return $this->runtimeUserUnavailableWarning($app, $runtimeApp, $instance, $exception);
        }

        try {
            $this->applyRuntime($app, $runtimeApp, $instance, $node);

            return null;
        } catch (AppRuntimeUserUnavailableException $retryException) {
            return $this->runtimeUserUnavailableWarning($app, $runtimeApp, $instance, $retryException);
        } catch (AppRuntimeImageUnavailableException $retryException) {
            return $this->phpVersionUnavailableWarning($runtimeApp, $retryException);
        } catch (AppRuntimeContainerApplyException $retryException) {
            return $this->runtimeContainerWarning(
                $runtimeApp,
                $retryException->hadExistingContainer,
                $retryException,
            );
        } catch (Throwable $retryException) {
            return $this->runtimeContainerWarning($runtimeApp, hadExistingContainer: false, exception: $retryException);
        }
    }

    private function ensureRuntimeTlsMaterial(App $app, Node $owningNode): void
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
    private function runtimeContainerWarning(App $app, bool $hadExistingContainer, Throwable $exception): array
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
    private function phpVersionUnavailableWarning(App $app, AppRuntimeImageUnavailableException $exception): array
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
    private function runtimeUserUnavailableWarning(
        App $app,
        App $runtimeApp,
        Instance $instance,
        AppRuntimeUserUnavailableException $exception,
    ): array {
        return [
            'code' => 'instance.security.system_user',
            'family' => 'instance',
            'message' => "Production runtime user '{$exception->runtimeUser}' for instance '{$app->name}.{$instance->name}' could not be provisioned on '{$runtimeApp->node?->name}': {$exception->getMessage()}",
            'next_command' => "doctor --family=instance --instance={$app->name}.{$instance->name} --restore",
        ];
    }
}
