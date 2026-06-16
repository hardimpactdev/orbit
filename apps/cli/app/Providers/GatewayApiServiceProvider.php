<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Trust\TrustStoreInstallReason;
use App\Exceptions\OrbitConfigStoreException;
use App\Services\Dns\LocalResolver;
use App\Services\Dns\ResolvesLocalDns;
use App\Services\Gateway\FetchesGatewayRootCa;
use App\Services\Gateway\FetchGatewayRootCa;
use App\Services\Gateway\VerifiesGatewayIdentity;
use App\Services\Gateway\VerifyGatewayIdentity;
use App\Services\GatewayApiClient;
use App\Services\GatewayLogStreamClient;
use App\Services\GatewayOperationEventStreamClient;
use App\Services\GatewayOperationFollower;
use App\Services\GatewayStreamClient;
use App\Services\OrbitConfigStore;
use App\Services\Profile\CurlProfileRequestProfiler;
use App\Services\Profile\ProfileRequestProfiler;
use App\Services\Trust\LinuxTrustStoreInstaller;
use App\Services\Trust\MacOsTrustStoreInstaller;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
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

        $this->app->bind(ProfileRequestProfiler::class, function (): ProfileRequestProfiler {
            $config = $this->gatewayConnectionConfig();

            return new CurlProfileRequestProfiler(
                caPemPath: $config['ca_pem_path'],
                timeoutSeconds: $config['timeout'],
            );
        });

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

        $this->app->bind(TrustStoreInstaller::class, fn (): TrustStoreInstaller => match (strtolower(PHP_OS_FAMILY)) {
            'darwin' => new MacOsTrustStoreInstaller,
            'linux' => new LinuxTrustStoreInstaller,
            default => throw new TrustStoreInstallException(
                'Unsupported platform for trust store operations.',
                TrustStoreInstallReason::UnsupportedPlatform,
            ),
        });

        $this->app->singleton(GatewayApiClient::class, function (): GatewayApiClient {
            $config = $this->gatewayConnectionConfig();

            return new GatewayApiClient(
                baseUrl: $config['base_url'],
                timeout: $config['timeout'],
                caPemPath: $config['ca_pem_path'],
            );
        });

        $this->app->singleton(GatewayStreamClient::class, function (): GatewayStreamClient {
            $config = $this->gatewayConnectionConfig();

            return new GatewayStreamClient(
                baseUrl: $config['base_url'],
                timeout: $config['timeout'],
                caPemPath: $config['ca_pem_path'],
            );
        });

        $this->app->singleton(GatewayOperationEventStreamClient::class, function (): GatewayOperationEventStreamClient {
            $config = $this->gatewayConnectionConfig();

            return new GatewayOperationEventStreamClient(
                baseUrl: $config['base_url'],
                timeout: $config['timeout'],
                caPemPath: $config['ca_pem_path'],
            );
        });

        $this->app->bind(GatewayOperationFollower::class, fn (): GatewayOperationFollower => new GatewayOperationFollower(
            events: $this->app->make(GatewayOperationEventStreamClient::class),
            reconnectSleepMs: $this->positiveInteger(config('orbit.gateway.operation_follow_reconnect_sleep_ms'), 500),
            maxEmptyReplays: $this->nonNegativeInteger(config('orbit.gateway.operation_follow_max_empty_replays'), 0),
            maxTransientFailures: $this->nonNegativeInteger(config('orbit.gateway.operation_follow_max_transient_failures'), 120),
        ));

        $this->app->singleton(GatewayLogStreamClient::class, function (): GatewayLogStreamClient {
            $config = $this->gatewayConnectionConfig();

            return new GatewayLogStreamClient(
                baseUrl: $config['base_url'],
                timeout: $config['timeout'],
                caPemPath: $config['ca_pem_path'],
            );
        });
    }

    /**
     * @return array{base_url: string|null, timeout: int, ca_pem_path: string|null, self_mode: string|null}
     */
    private function gatewayConnectionConfig(): array
    {
        $jsonGateway = $this->safeActiveGateway();

        $envUrl = $this->nullableString(config('orbit.gateway.url'));
        $jsonUrl = is_array($jsonGateway) ? $this->nullableString($jsonGateway['url'] ?? null) : null;

        $envTimeout = config('orbit.gateway.timeout');
        $jsonTimeout = is_array($jsonGateway) ? ($jsonGateway['timeout'] ?? null) : null;

        $envCaPemPath = $this->nullableString(config('orbit.gateway.ca_pem_path'));
        $jsonCaPemPath = is_array($jsonGateway) ? $this->nullableString($jsonGateway['ca_pem_path'] ?? null) : null;

        $jsonSelfMode = is_array($jsonGateway) ? $this->nullableString($jsonGateway['self_mode'] ?? null) : null;

        return [
            'base_url' => $envUrl ?? $jsonUrl,
            'timeout' => $this->positiveInteger($envTimeout ?? $jsonTimeout, OrbitConfigStore::DEFAULT_TIMEOUT_SECONDS),
            'ca_pem_path' => $envCaPemPath ?? $jsonCaPemPath,
            'self_mode' => $jsonSelfMode ?? OrbitConfigStore::DEFAULT_SELF_MODE,
        ];
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

    private function nonNegativeInteger(mixed $value, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        $value = (int) $value;

        return $value >= 0 ? $value : $default;
    }
}
