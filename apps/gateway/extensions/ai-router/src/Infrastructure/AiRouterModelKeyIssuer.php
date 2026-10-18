<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmConfig;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\TaskVmModelKeys;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use SensitiveParameter;

/** Issues task VM model keys at the CLIProxyAPI of the ai-router extension, with its management key and one anchor key. */
final readonly class AiRouterModelKeyIssuer implements ModelKeyIssuer
{
    public function __construct(
        private TaskVmSettings $settings,
        private AiRouterState $proxy,
        private SettingRepository $gatewaySettings,
        private TaskVmModelKeys $keys,
    ) {}

    public function assertReady(): void
    {
        $key = $this->proxy->managementKey();
        if ($key === null || $key === '') {
            throw new ResourceOperationException(
                'ai_router.disabled',
                'Set up the ai-router extension with ai-router:setup first. Task VMs get their model keys from its CLIProxyAPI.',
                409,
            );
        }
    }

    public function origin(): string
    {
        $origin = $this->settings->modelProxyOrigin ?? throw TaskVmConfig::invalid('model_proxy_origin is required to prepare a task VM.');
        if (rtrim((string) $this->proxy->routerUrl(), '/') !== $origin) {
            throw TaskVmConfig::invalid('model_proxy_origin must be the CLIProxyAPI URL of the ai-router extension.');
        }

        return $origin;
    }

    public function issue(string $origin, #[SensitiveParameter] string $key): void
    {
        $this->keys->ensure($origin, $this->managementKey(), $this->anchor($origin), $key);
    }

    public function revoke(string $origin, #[SensitiveParameter] string $key): void
    {
        $this->keys->revoke($origin, $this->managementKey(), $this->anchor($origin), $key);
    }

    private function managementKey(): string
    {
        $key = $this->proxy->managementKey();
        if ($key === null || $key === '') {
            throw new TaskVmException(TaskVmError::ModelKeyFailed, 'Set up the ai-router extension: it holds the CLIProxyAPI management key.', 409);
        }

        return $key;
    }

    /** One random anchor key per origin keeps CLIProxyAPI's key list from ever becoming empty, which would open it. */
    private function anchor(string $origin): string
    {
        $scope = new SettingScope(SettingScopeType::Gateway);
        $name = 'ai_router.anchor.'.hash('sha256', $origin);
        $key = $this->gatewaySettings->get($scope, $name);
        if ($key === null) {
            $key = bin2hex(random_bytes(32));
            $this->gatewaySettings->put($scope, $name, $key, SettingValueProtection::Secret);
        }

        return $key;
    }
}
