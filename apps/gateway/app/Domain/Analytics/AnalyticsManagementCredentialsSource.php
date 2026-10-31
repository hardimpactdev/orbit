<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Existing protected settings, transported only by the shared exact-Node private endpoint. */
final readonly class AnalyticsManagementCredentialsSource implements OperationSecretSource
{
    public function __construct(private SettingRepository $settings, private AnalyticsStatsKeyStore $keys, private AnalyticsTrackingIntent $tracking) {}

    public function name(): string
    {
        return 'analytics.plausible_credentials';
    }

    public function targetTypes(): array
    {
        return ['instance'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'analytics.enable' && $operation->input['enabled'] === true ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id || $parameter !== 'plausible_credentials'
            || ! NodeRole::query()->whereKey($operation->input['assignment_id'])->where('node_id', $id)->where('role', 'analytics')->where('status', 'active')->exists()) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Plausible credential owner changed.', 409);
        }
        $this->tracking->assertCurrent($operation);
        $scope = new SettingScope(SettingScopeType::Gateway);
        $email = $this->settings->get($scope, 'analytics.management_email');
        $password = $this->settings->get($scope, 'analytics.management_password');
        $apiKey = $this->keys->get();
        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '' || $apiKey === null) {
            throw new ResourceOperationException('analytics.credentials_missing', 'Protected Plausible management credentials are required.', 409);
        }
        $domain = ValidatedData::string(ValidatedData::object($operation->input['site'])['domain']);
        $plugins = $this->settings->get($scope, 'analytics.plugins.'.$domain);

        return new OperationSecretValue(['email' => $email, 'password' => $password, 'apiKey' => $apiKey,
            ...($plugins === null ? [] : ['plugins' => ['domain' => $domain, 'token' => $plugins]])]);
    }
}
