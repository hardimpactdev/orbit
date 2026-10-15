<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmConfig;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\TaskVmModelKeys;
use SensitiveParameter;

/** Issues task VM model keys at the CLIProxyAPI of the proxycli extension, with its management key and one anchor key. */
final readonly class ProxyCliModelKeyIssuer implements ModelKeyIssuer
{
    public function __construct(
        private TaskVmSettings $settings,
        private ProxyCliState $proxy,
        private SettingRepository $gatewaySettings,
        private TaskVmModelKeys $keys,
    ) {}

    public function origin(): string
    {
        $origin = $this->settings->modelProxyOrigin ?? throw TaskVmConfig::invalid('model_proxy_origin is required to prepare a task VM.');
        if (rtrim((string) $this->proxy->cliproxyUrl(), '/') !== $origin) {
            throw TaskVmConfig::invalid('model_proxy_origin must be the CLIProxyAPI URL of the proxycli extension.');
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
        $key = $this->proxy->cliproxyManagementKey();
        if ($key === null || $key === '') {
            throw new TaskVmException(TaskVmError::ModelKeyFailed, 'Set up the proxycli extension: it holds the CLIProxyAPI management key.', 409);
        }

        return $key;
    }

    /** One random anchor key per origin keeps CLIProxyAPI's key list from ever becoming empty, which would open it. */
    private function anchor(string $origin): string
    {
        $scope = new SettingScope(SettingScopeType::Gateway);
        $name = 'compute.model_proxy.anchor.'.hash('sha256', $origin);
        $key = $this->gatewaySettings->get($scope, $name);
        if ($key === null) {
            $key = bin2hex(random_bytes(32));
            $this->gatewaySettings->put($scope, $name, $key, SettingValueProtection::Secret);
        }

        return $key;
    }
}
