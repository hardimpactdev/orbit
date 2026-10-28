<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;

/** Assignment-owned Settings outlive teardown children and are finalized by the Node parent. */
final readonly class NodeRoleSettings
{
    public function __construct(private SettingRepository $settings) {}

    public function copyForTransfer(int $sourceId, int $targetId, RoleName $role): void
    {
        $source = new SettingScope(SettingScopeType::Node, $sourceId);
        $target = new SettingScope(SettingScopeType::Node, $targetId);
        foreach ($this->transferKeys($role) as $key) {
            $existing = $this->settings->get($target, $key);
            if (is_string($existing) && $existing !== '') {
                continue;
            }
            $value = $this->settings->get($source, $key);
            if (is_string($value) && $value !== '') {
                $this->settings->put($target, $key, $value, SettingValueProtection::Secret);
            }
        }
    }

    public function removeTransferred(int $sourceId, RoleName $role): void
    {
        foreach ($this->transferKeys($role) as $key) {
            $this->settings->delete(new SettingScope(SettingScopeType::Node, $sourceId), $key);
        }
    }

    /** @return list<string> */
    private function transferKeys(RoleName $role): array
    {
        return match ($role) {
            RoleName::WebSocket => ['websocket.reverb.laravel_app_key'],
            RoleName::Metrics => ['metrics.grafana.admin_password', 'metrics.grafana.pending_admin_password'],
            default => [],
        };
    }

    public function remove(int $nodeId, RoleName $role, bool $purgeData, bool $offline): void
    {
        $keys = match ($role) {
            RoleName::WebSocket => $this->transferKeys($role),
            RoleName::Analytics => ['analytics.secret_key_base', 'analytics.plausible_version', 'analytics.postgres_process_id', 'analytics.clickhouse_process_id'],
            RoleName::Metrics => $purgeData && ! $offline ? $this->transferKeys($role) : [],
            default => [],
        };
        $scope = new SettingScope(SettingScopeType::Node, $nodeId);
        foreach ($keys as $key) {
            $this->settings->delete($scope, $key);
        }
    }
}
