<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\ResourceOperationException;
use SensitiveParameter;

/** Protected Gateway credentials and the admission-only shared execution copy. */
final readonly class AnalyticsPlausibleCredentials
{
    private const string Email = 'analytics.management_email';

    private const string Password = 'analytics.management_password';

    public function __construct(private SettingRepository $settings, private AnalyticsStatsKeyStore $keys) {}

    public function storeManagement(#[SensitiveParameter] ?string $email, #[SensitiveParameter] ?string $password): void
    {
        foreach ([self::Email => $email, self::Password => $password] as $key => $value) {
            if ($value !== null) {
                $this->settings->put(new SettingScope(SettingScopeType::Gateway), $key, $value, SettingValueProtection::Secret);
            }
        }
    }

    /** @return array{email:bool,password:bool} */
    public function managementStatus(): array
    {
        $scope = new SettingScope(SettingScopeType::Gateway);

        return ['email' => ($this->settings->get($scope, self::Email) ?? '') !== '',
            'password' => ($this->settings->get($scope, self::Password) ?? '') !== ''];
    }

    public function clearManagement(): void
    {
        foreach ([self::Email, self::Password] as $key) {
            $this->settings->delete(new SettingScope(SettingScopeType::Gateway), $key);
        }
    }

    /** @return array{email:string,password:string,apiKey:string,plugins?:array{domain:string,token:string}} */
    public function forSite(string $domain, bool $goals): array
    {
        $scope = new SettingScope(SettingScopeType::Gateway);
        $email = $this->settings->get($scope, self::Email);
        $password = $this->settings->get($scope, self::Password);
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
