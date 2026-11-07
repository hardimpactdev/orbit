<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use SensitiveParameter;

/** The two router keys the Gateway keeps as secret settings. Each is made once, on first use. */
final readonly class AiRouterSecrets
{
    public function __construct(private SettingRepository $settings) {}

    /**
     * One random anchor key per router origin. It keeps CLIProxyAPI's key list from ever becoming empty, which would open the router,
     * and it is the real key the task VM guard swaps in upstream. It never leaves the Gateway and the router's Node.
     */
    public function anchor(string $origin): string
    {
        return $this->secret('ai_router.anchor.'.hash('sha256', $origin));
    }

    /**
     * The one placeholder key every task VM holds ([Credentials in task VMs](/reference/tasks#credentials-in-task-vms)). Only the
     * guard accepts it; the router never has it. It changes only on an incident.
     */
    public function task(): string
    {
        return $this->secret('ai_router.task_vm_key');
    }

    private function secret(string $name): string
    {
        $scope = new SettingScope(SettingScopeType::Gateway);
        $key = $this->settings->get($scope, $name);
        if (! is_string($key)) {
            $key = bin2hex(random_bytes(32));
            $this->put($scope, $name, $key);
        }

        return $key;
    }

    private function put(SettingScope $scope, string $name, #[SensitiveParameter] string $key): void
    {
        $this->settings->put($scope, $name, $key, SettingValueProtection::Secret);
    }
}
