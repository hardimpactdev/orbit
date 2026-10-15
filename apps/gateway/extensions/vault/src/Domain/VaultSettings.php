<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use Carbon\CarbonImmutable;
use JsonException;

/** The stored setup, encrypted, and the outcome of the last sync. */
final readonly class VaultSettings
{
    private const string Configuration = 'vault.configuration';

    private const string LastSyncedAt = 'vault.last_synced_at';

    private const string LastVerifiedAt = 'vault.last_verified_at';

    private const string LastError = 'vault.last_error';

    public function __construct(private SettingRepository $settings) {}

    public function configuration(): ?VaultConfiguration
    {
        $stored = $this->settings->get($this->scope(), self::Configuration);

        if ($stored === null) {
            return null;
        }

        try {
            $decoded = json_decode($stored, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? VaultConfiguration::fromStoredArray($decoded) : null;
    }

    public function put(VaultConfiguration $configuration): void
    {
        $this->settings->put(
            $this->scope(),
            self::Configuration,
            json_encode($configuration->toStoredArray(), JSON_THROW_ON_ERROR),
            SettingValueProtection::Secret,
        );
    }

    public function clear(): void
    {
        foreach ([self::Configuration, self::LastSyncedAt, self::LastVerifiedAt, self::LastError] as $key) {
            $this->settings->delete($this->scope(), $key);
        }
    }

    public function lastSyncedAt(): ?string
    {
        return $this->settings->get($this->scope(), self::LastSyncedAt);
    }

    public function lastVerifiedAt(): ?CarbonImmutable
    {
        $stored = $this->settings->get($this->scope(), self::LastVerifiedAt);

        return $stored === null ? null : CarbonImmutable::parse($stored);
    }

    public function forgetVerification(): void
    {
        $this->settings->delete($this->scope(), self::LastVerifiedAt);
    }

    public function recordVerified(CarbonImmutable $at): void
    {
        $this->settings->put($this->scope(), self::LastVerifiedAt, $at->toIso8601ZuluString());
    }

    public function recordSuccess(CarbonImmutable $at): void
    {
        $this->settings->put($this->scope(), self::LastSyncedAt, $at->toIso8601ZuluString());
        $this->settings->delete($this->scope(), self::LastError);
    }

    public function recordError(string $code, string $message): void
    {
        $this->settings->put(
            $this->scope(),
            self::LastError,
            json_encode(['code' => $code, 'message' => $message], JSON_THROW_ON_ERROR),
        );
    }

    /** @return array{code: string, message: string}|null */
    public function lastError(): ?array
    {
        $stored = $this->settings->get($this->scope(), self::LastError);

        if ($stored === null) {
            return null;
        }

        $decoded = json_decode($stored, true);

        return is_array($decoded) && is_string($decoded['code'] ?? null) && is_string($decoded['message'] ?? null)
            ? ['code' => $decoded['code'], 'message' => $decoded['message']]
            : null;
    }

    private function scope(): SettingScope
    {
        return new SettingScope(SettingScopeType::Gateway);
    }
}
