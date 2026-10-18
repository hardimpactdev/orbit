<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\ResourceOperationException;

final readonly class AiRouterState
{
    public function __construct(
        private SettingRepository $settings,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->get($this->scope(), AiRouterSettings::Enabled) === '1';
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new ResourceOperationException(
                'ai_router.disabled',
                'The ai-router extension is disabled.',
                409,
            );
        }
    }

    public function nodeId(): ?int
    {
        $value = $this->settings->get($this->scope(), AiRouterSettings::NodeId);

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    public function cacheConnection(): ?string
    {
        return $this->settings->get($this->scope(), AiRouterSettings::CacheConnection);
    }

    public function routerUrl(): ?string
    {
        return $this->settings->get($this->scope(), AiRouterSettings::RouterUrl);
    }

    public function managementKey(): ?string
    {
        return $this->settings->get($this->scope(), AiRouterSettings::ManagementKey);
    }

    public function readToken(): ?string
    {
        return $this->settings->get($this->scope(), AiRouterSettings::ReadToken);
    }

    public function controlToken(): ?string
    {
        return $this->settings->get($this->scope(), AiRouterSettings::ControlToken);
    }

    public function enable(
        int $nodeId,
        string $cacheConnection,
        string $routerUrl,
        string $managementKey,
        string $readToken,
        string $controlToken,
    ): void {
        $scope = $this->scope();
        $this->settings->put($scope, AiRouterSettings::Enabled, '1');
        $this->settings->put($scope, AiRouterSettings::NodeId, (string) $nodeId);
        $this->settings->put($scope, AiRouterSettings::CacheConnection, $cacheConnection);
        $this->settings->put($scope, AiRouterSettings::RouterUrl, $routerUrl);
        $this->settings->put($scope, AiRouterSettings::ManagementKey, $managementKey, SettingValueProtection::Secret);
        $this->settings->put($scope, AiRouterSettings::ReadToken, $readToken, SettingValueProtection::Secret);
        $this->settings->put($scope, AiRouterSettings::ControlToken, $controlToken, SettingValueProtection::Secret);
    }

    public function disable(): void
    {
        $scope = $this->scope();
        $this->settings->put($scope, AiRouterSettings::Enabled, '0');
        $this->settings->delete($scope, AiRouterSettings::ManagementKey);
        $this->settings->delete($scope, AiRouterSettings::ReadToken);
        $this->settings->delete($scope, AiRouterSettings::ControlToken);
    }

    private function scope(): SettingScope
    {
        return new SettingScope(SettingScopeType::Gateway);
    }
}
