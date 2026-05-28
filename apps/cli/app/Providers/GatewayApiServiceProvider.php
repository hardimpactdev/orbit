<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exceptions\OrbitConfigStoreException;
use App\Services\Dns\LocalResolver;
use App\Services\Dns\ResolvesLocalDns;
use App\Services\Gateway\FetchesGatewayRootCa;
use App\Services\Gateway\FetchGatewayRootCa;
use App\Services\Gateway\VerifiesGatewayIdentity;
use App\Services\Gateway\VerifyGatewayIdentity;
use App\Services\GatewayApiClient;
use App\Services\OrbitConfigStore;
use App\Services\Trust\LinuxTrustStoreInstaller;
use App\Services\Trust\MacOsTrustStoreInstaller;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
use App\Services\Trust\TrustStoreInstallReason;
use App\Services\Updates\LocalCheckoutUpdater;
use App\Services\Updates\RunsLocalUpdate;
use App\Services\WireGuard\ResolvesGatewayAddress;
use App\Services\WireGuard\WireGuardGatewayAddressResolver;
use Illuminate\Support\ServiceProvider;

/**
 * Per decision D13: this closure is the home of the env-then-JSON precedence chain for
 * gateway configuration. It reads `OrbitConfigStore` for the active gateway entry, then
 * overlays env values from `config/orbit.php` (which are env-only). The chain lives here
 * instead of in `config/orbit.php` so it survives `config:cache` and does not run at
 * config-load time (Laravel loads `config/*.php` before service providers register).
 *
 * Per decision D2: there is no bearer identity. `GatewayApiClient` no longer accepts one.
 */
final class GatewayApiServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->bind(ResolvesLocalDns::class, fn (): ResolvesLocalDns => new LocalResolver);

        $this->app->bind(RunsLocalUpdate::class, LocalCheckoutUpdater::class);

        $this->app->singleton(OrbitConfigStore::class, function (): OrbitConfigStore {
            $override = env('ORBIT_CONFIG_PATH');

            return new OrbitConfigStore(
                overridePath: is_string($override) && $override !== '' ? $override : null,
            );
        });

        $this->app->bind(FetchesGatewayRootCa::class, fn (): FetchesGatewayRootCa => new FetchGatewayRootCa);

        $this->app->bind(ResolvesGatewayAddress::class, fn (): ResolvesGatewayAddress => new WireGuardGatewayAddressResolver);

        $this->app->bind(VerifiesGatewayIdentity::class, fn (): VerifiesGatewayIdentity => new VerifyGatewayIdentity);

        $this->app->bind(TrustStoreInstaller::class, function (): TrustStoreInstaller {
            return match (strtolower(PHP_OS_FAMILY)) {
                'darwin' => new MacOsTrustStoreInstaller,
                'linux' => new LinuxTrustStoreInstaller,
                default => throw new TrustStoreInstallException(
                    'Unsupported platform for trust store operations.',
                    TrustStoreInstallReason::UnsupportedPlatform,
                ),
            };
        });

        $this->app->singleton(GatewayApiClient::class, function (): GatewayApiClient {
            $jsonGateway = $this->safeActiveGateway();

            $envUrl = $this->nullableString(config('orbit.gateway.url'));
            $jsonUrl = is_array($jsonGateway) ? $this->nullableString($jsonGateway['url'] ?? null) : null;

            $envTimeout = config('orbit.gateway.timeout');
            $jsonTimeout = is_array($jsonGateway) ? ($jsonGateway['timeout'] ?? null) : null;

            return new GatewayApiClient(
                baseUrl: $envUrl ?? $jsonUrl,
                timeout: $this->positiveInteger($envTimeout ?? $jsonTimeout, OrbitConfigStore::DEFAULT_TIMEOUT_SECONDS),
            );
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeActiveGateway(): ?array
    {
        try {
            return $this->app->make(OrbitConfigStore::class)->activeGateway();
        } catch (OrbitConfigStoreException) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function positiveInteger(mixed $value, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        $value = (int) $value;

        return $value > 0 ? $value : $default;
    }
}
