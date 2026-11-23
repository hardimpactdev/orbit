<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Push;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Data\ConnPushCredentialsStatusData;

/**
 * Gateway-scoped encrypted storage for Apple APNs provider credentials.
 * The private key is encrypted via SettingValueProtection::Secret and never returned in status reads.
 */
final readonly class ConnPushCredentialsStore
{
    public const string KeyIdSetting = 'conn.push.apns_key_id';

    public const string TeamIdSetting = 'conn.push.apns_team_id';

    public const string PrivateKeySetting = 'conn.push.apns_private_key';

    public function __construct(private SettingRepository $settings) {}

    public function get(): ?ConnPushCredentials
    {
        $scope = $this->scope();
        $keyId = $this->settings->get($scope, self::KeyIdSetting);
        $teamId = $this->settings->get($scope, self::TeamIdSetting);
        $privateKey = $this->settings->get($scope, self::PrivateKeySetting);

        if (! is_string($keyId) || $keyId === ''
            || ! is_string($teamId) || $teamId === ''
            || ! is_string($privateKey) || $privateKey === '') {
            return null;
        }

        return new ConnPushCredentials($keyId, $teamId, $privateKey);
    }

    public function status(): ConnPushCredentialsStatusData
    {
        $scope = $this->scope();
        $keyId = $this->settings->get($scope, self::KeyIdSetting);
        $teamId = $this->settings->get($scope, self::TeamIdSetting);
        $privateKey = $this->settings->get($scope, self::PrivateKeySetting);

        $configured = is_string($keyId) && $keyId !== ''
            && is_string($teamId) && $teamId !== ''
            && is_string($privateKey) && $privateKey !== '';

        return new ConnPushCredentialsStatusData(
            configured: $configured,
            keyId: $configured ? $keyId : null,
            teamId: $configured ? $teamId : null,
        );
    }

    public function put(ConnPushCredentials $credentials): ConnPushCredentialsStatusData
    {
        $scope = $this->scope();

        DB::transaction(function () use ($scope, $credentials): void {
            $this->settings->put($scope, self::KeyIdSetting, $credentials->keyId);
            $this->settings->put($scope, self::TeamIdSetting, $credentials->teamId);
            $this->settings->put($scope, self::PrivateKeySetting, $credentials->privateKey, SettingValueProtection::Secret);
        });

        return new ConnPushCredentialsStatusData(
            configured: true,
            keyId: $credentials->keyId,
            teamId: $credentials->teamId,
        );
    }

    private function scope(): SettingScope
    {
        return new SettingScope(SettingScopeType::Gateway);
    }
}
