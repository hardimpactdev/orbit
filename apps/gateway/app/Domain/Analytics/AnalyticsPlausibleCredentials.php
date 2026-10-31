<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\ResourceOperationException;

/** Admission-only source for the shared Swamp execution copy. */
final readonly class AnalyticsPlausibleCredentials
{
    public function __construct(private SettingRepository $settings, private AnalyticsStatsKeyStore $keys) {}

    /** @return array{email:string,password:string,apiKey:string,plugins?:array{domain:string,token:string}} */
    public function forSite(string $domain, bool $goals): array
    {
        $scope = new SettingScope(SettingScopeType::Gateway);
        $email = $this->settings->get($scope, 'analytics.management_email');
        $password = $this->settings->get($scope, 'analytics.management_password');
        $apiKey = $this->keys->get();
        $token = $this->settings->get($scope, 'analytics.plugins.'.$domain);
        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '' || $apiKey === null) {
            throw new ResourceOperationException('analytics.credentials_missing', 'Protected Plausible management credentials are required.', 409);
        }
        if ($goals && (! is_string($token) || preg_match('/\A[\x21-\x7e]+\z/D', $token) !== 1)) {
            throw new ResourceOperationException('analytics.goals_credentials_missing', 'The admitted site requires its Plausible plugins token.', 409);
        }

        return ['email' => $email, 'password' => $password, 'apiKey' => $apiKey,
            ...($token === null ? [] : ['plugins' => ['domain' => $domain, 'token' => $token]])];
    }
}
