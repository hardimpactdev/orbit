<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\LogsCommandActivity;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Contracts\Loggable;
use App\Models\LocalGatewaySettings;
use App\Services\Gateway\FetchGatewayRootCa;
use App\Services\Gateway\RootCaFetchResult;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
use App\Services\Trust\TrustStoreInstallReason;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use PDOException;
use RuntimeException;

#[Signature('gateway:trust
    {--json : Output JSON}')]
#[Description('Trust the gateway root CA in the local OS trust store')]
class GatewayTrustCommand extends Command implements Loggable
{
    use LogsCommandActivity;
    use WithSpinner;
    use WithStepTree;

    private const string LABEL = 'orbit';

    private ?string $activityGatewayUrl = null;

    private ?string $activityGatewayIp = null;

    private ?string $activityCaSha256 = null;

    private ?string $activityStatus = null;

    public function handle(FetchGatewayRootCa $fetch): int
    {
        $this->bootActivityLog();

        try {
            return $this->executeGatewayTrust($fetch);
        } finally {
            $this->finishActivityLog();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return array_filter([
            'gateway_url' => $this->activityGatewayUrl,
            'gateway_ip' => $this->activityGatewayIp,
            'ca_sha256' => $this->activityCaSha256,
            'status' => $this->activityStatus,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function description(): ?string
    {
        if ($this->activityStatus === 'already_trusted' && $this->activityGatewayUrl !== null) {
            return "Gateway CA already trusted for {$this->activityGatewayUrl}";
        }

        if ($this->activityStatus === 'trusted' && $this->activityGatewayUrl !== null) {
            return "Gateway CA trusted for {$this->activityGatewayUrl}";
        }

        return 'Gateway CA trust attempted';
    }

    private function executeGatewayTrust(FetchGatewayRootCa $fetch): int
    {
        try {
            $installer = app(TrustStoreInstaller::class);
        } catch (TrustStoreInstallException $e) {
            if ($e->reason === TrustStoreInstallReason::UnsupportedPlatform) {
                return $this->failCommand(
                    code: 'node.unsupported_platform',
                    message: 'This platform does not support automatic gateway CA trust installation.',
                    meta: ['platform' => PHP_OS_FAMILY, 'reason' => 'unsupported_trust_store'],
                );
            }

            throw $e;
        } catch (RuntimeException $e) {
            return $this->failCommand(
                code: 'node.unsupported_platform',
                message: 'This platform does not support automatic gateway CA trust installation.',
                meta: ['platform' => PHP_OS_FAMILY, 'reason' => 'unsupported_trust_store'],
            );
        }

        $gateway = $this->resolveGateway();

        if (array_key_exists('code', $gateway)) {
            return $this->failCommand(
                code: $gateway['code'],
                message: $gateway['message'],
                meta: $gateway['meta'],
            );
        }

        $gatewayUrl = $gateway['url'];
        $gatewayIp = $gateway['ip'];
        $this->activityGatewayUrl = $gatewayUrl;
        $this->activityGatewayIp = $gatewayIp;

        if (! $this->wantsJson()) {
            return $this->executeGatewayTrustHuman($fetch, $installer, $gatewayUrl, $gatewayIp);
        }

        try {
            $result = $fetch->handle($gatewayIp);
        } catch (RuntimeException|ConnectionException $e) {
            if ($e instanceof ConnectionException || str_contains($e->getMessage(), 'HTTP')) {
                return $this->failCommand(
                    code: 'gateway_unavailable',
                    message: "Could not fetch the gateway CA from {$gatewayUrl}.",
                    meta: ['gateway_url' => $gatewayUrl, 'endpoint' => '/api/ca/root'],
                );
            }

            return $this->failCommand(
                code: 'node.gateway_api_error',
                message: 'Gateway returned invalid CA material.',
                meta: ['gateway_url' => $gatewayUrl, 'endpoint' => '/api/ca/root', 'reason' => 'invalid_trust_material'],
            );
        }

        $pemPath = $this->persistPem($result);

        if ($pemPath === null) {
            return $this->failCommand(
                code: 'node.local_config_write_failed',
                message: 'Failed to store local gateway CA material.',
                meta: ['gateway_url' => $gatewayUrl, 'reason' => 'metadata_write_failed'],
            );
        }

        if ($this->isAlreadyTrusted($installer, $pemPath, $result->sha256)) {
            $this->captureActivitySuccess($result->sha256, 'already_trusted');

            return $this->jsonSuccess([
                'gateway_trust' => [
                    'gateway_url' => $gatewayUrl,
                    'trusted' => true,
                    'status' => 'already_trusted',
                    'ca_sha256' => $result->sha256,
                ],
            ], ['trusted_at' => $this->trustedAt()->toIso8601String()]);
        }

        try {
            $installer->trustCa($pemPath, self::LABEL);
        } catch (TrustStoreInstallException $e) {
            if ($e->reason === TrustStoreInstallReason::UnsupportedPlatform) {
                return $this->failCommand(
                    code: 'node.unsupported_platform',
                    message: 'This platform does not support automatic gateway CA trust installation.',
                    meta: ['platform' => PHP_OS_FAMILY, 'reason' => 'unsupported_trust_store'],
                );
            }

            return $this->failCommand(
                code: 'node.local_config_write_failed',
                message: 'Failed to install the gateway CA into the local trust store.',
                meta: ['gateway_url' => $gatewayUrl, 'reason' => 'trust_store_failed'],
            );
        }

        try {
            LocalGatewaySettings::current()->fill([
                'gateway_url' => $gatewayUrl,
                'gateway_wg_ip' => $gatewayIp,
                'ca_sha256' => $result->sha256,
                'ca_pem_path' => $pemPath,
                'trusted_at' => now(),
            ])->save();
        } catch (RuntimeException) {
            return $this->failCommand(
                code: 'node.local_config_write_failed',
                message: 'Failed to write local trust metadata.',
                meta: ['gateway_url' => $gatewayUrl, 'reason' => 'metadata_write_failed'],
            );
        }

        $this->captureActivitySuccess($result->sha256, 'trusted');

        return $this->jsonSuccess([
            'gateway_trust' => [
                'gateway_url' => $gatewayUrl,
                'trusted' => true,
                'status' => 'trusted',
                'ca_sha256' => $result->sha256,
            ],
        ], ['trusted_at' => $this->trustedAt()->toIso8601String()]);
    }

    private function executeGatewayTrustHuman(
        FetchGatewayRootCa $fetch,
        TrustStoreInstaller $installer,
        string $gatewayUrl,
        string $gatewayIp,
    ): int {
        $result = null;
        $pemPath = null;
        $alreadyTrusted = false;

        $exitCode = $this->runStepTree(
            'Trust Gateway CA',
            [
                [
                    'label' => 'Resolve configured gateway',
                    'run' => fn (): string => $gatewayUrl,
                ],
                [
                    'label' => 'Fetch trust material',
                    'run' => function () use ($fetch, $gatewayUrl, $gatewayIp, &$result, &$pemPath): string {
                        try {
                            $result = $fetch->handle($gatewayIp);
                        } catch (RuntimeException|ConnectionException $e) {
                            if ($e instanceof ConnectionException || str_contains($e->getMessage(), 'HTTP')) {
                                return "fail:Could not fetch the gateway CA from {$gatewayUrl}.";
                            }

                            return 'fail:Gateway returned invalid CA material.';
                        }

                        $pemPath = $this->persistPem($result);

                        if ($pemPath === null) {
                            return 'fail:Failed to store local gateway CA material.';
                        }

                        return $result->sha256;
                    },
                ],
                [
                    'label' => 'Check local trust',
                    'run' => function () use ($installer, &$result, &$pemPath, &$alreadyTrusted): string {
                        if (! $result instanceof RootCaFetchResult || $pemPath === null) {
                            return 'fail:Gateway returned invalid CA material.';
                        }

                        $alreadyTrusted = $this->isAlreadyTrusted($installer, $pemPath, $result->sha256);

                        return $alreadyTrusted ? 'already trusted' : 'trust required';
                    },
                ],
                [
                    'label' => 'Install local trust',
                    'run' => function () use ($installer, &$result, &$pemPath, &$alreadyTrusted): string {
                        if ($alreadyTrusted) {
                            return 'skip:Already trusted';
                        }

                        if (! $result instanceof RootCaFetchResult || $pemPath === null) {
                            return 'fail:Gateway returned invalid CA material.';
                        }

                        try {
                            $installer->trustCa($pemPath, self::LABEL);
                        } catch (TrustStoreInstallException $e) {
                            if ($e->reason === TrustStoreInstallReason::UnsupportedPlatform) {
                                return 'fail:This platform does not support automatic gateway CA trust installation.';
                            }

                            return 'fail:Failed to install the gateway CA into the local trust store.';
                        }

                        return 'trusted';
                    },
                ],
                [
                    'label' => 'Store trust metadata',
                    'run' => function () use ($gatewayUrl, $gatewayIp, &$result, &$pemPath, &$alreadyTrusted): string {
                        if (! $result instanceof RootCaFetchResult || $pemPath === null) {
                            return 'fail:Gateway returned invalid CA material.';
                        }

                        if ($alreadyTrusted) {
                            return 'skip:Already trusted';
                        }

                        try {
                            LocalGatewaySettings::current()->fill([
                                'gateway_url' => $gatewayUrl,
                                'gateway_wg_ip' => $gatewayIp,
                                'ca_sha256' => $result->sha256,
                                'ca_pem_path' => $pemPath,
                                'trusted_at' => now(),
                            ])->save();
                        } catch (RuntimeException) {
                            return 'fail:Failed to write local trust metadata.';
                        }

                        return 'metadata stored';
                    },
                ],
            ],
            doneFooter: "Gateway CA trust completed for {$gatewayUrl}.",
            failFooter: "Failed to trust Gateway CA for {$gatewayUrl}.",
        );

        if ($exitCode !== self::SUCCESS || ! $result instanceof RootCaFetchResult) {
            return self::FAILURE;
        }

        $status = $alreadyTrusted ? 'already_trusted' : 'trusted';
        $statusLabel = $status === 'already_trusted' ? 'already trusted' : 'trusted';
        $this->captureActivitySuccess($result->sha256, $status);
        $this->line("Gateway CA {$statusLabel} for {$gatewayUrl}.");

        return self::SUCCESS;
    }

    private function finishActivityLog(): void
    {
        try {
            $this->finalizeActivityLog();
        } catch (\Throwable) {
            // Activity logging must not change the documented gateway:trust result.
        }
    }

    /**
     * @return array{url: string, ip: string}|array{code: string, message: string, meta: array<string, mixed>}
     */
    private function resolveGateway(): array
    {
        try {
            $settings = LocalGatewaySettings::current();
        } catch (SQLiteDatabaseDoesNotExistException|PDOException $e) {
            $reason = $this->classifyLocalConfigReadFailure($e);

            return [
                'code' => 'node.local_config_read_failed',
                'message' => $this->localConfigReadFailureMessage($reason),
                'meta' => ['field' => 'gateway', 'reason' => $reason],
            ];
        }

        if ($settings->gateway_url !== null && $settings->gateway_url !== '') {
            return $this->normalizeEndpoint((string) $settings->gateway_url, (string) ($settings->gateway_wg_ip ?? $settings->gateway_url));
        }

        return [
            'code' => 'validation_failed',
            'message' => 'No gateway is configured. Run orbit gateway:add first.',
            'meta' => ['field' => 'gateway', 'reason' => 'missing'],
        ];
    }

    /**
     * @return array{url: string, ip: string}|array{code: string, message: string, meta: array<string, mixed>}
     */
    private function normalizeEndpoint(string $endpoint, string $ip): array
    {
        if ($endpoint === '' || $ip === '') {
            return [
                'code' => 'validation_failed',
                'message' => 'Configured gateway endpoint is invalid.',
                'meta' => ['field' => 'gateway', 'reason' => 'invalid'],
            ];
        }

        $url = str_starts_with($endpoint, 'http') ? $endpoint : "https://{$endpoint}";
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === '' || $host === false || $host === null) {
            return [
                'code' => 'validation_failed',
                'message' => 'Configured gateway endpoint is invalid.',
                'meta' => ['field' => 'gateway', 'reason' => 'invalid'],
            ];
        }

        // Reject URLs that look malformed even if parse_url extracted a host
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return [
                'code' => 'validation_failed',
                'message' => 'Configured gateway endpoint is invalid.',
                'meta' => ['field' => 'gateway', 'reason' => 'invalid'],
            ];
        }

        return ['url' => $url, 'ip' => $ip];
    }

    private function classifyLocalConfigReadFailure(SQLiteDatabaseDoesNotExistException|PDOException $e): string
    {
        $message = strtolower($e->getMessage());

        if ($e->getPrevious() !== null) {
            $message .= ' '.strtolower($e->getPrevious()->getMessage());
        }

        if (str_contains($message, 'no such table')
            && str_contains($message, 'local_gateway_settings')) {
            return 'settings_table_missing';
        }

        if (str_contains($message, 'locked')
            || str_contains($message, 'sqlite_busy')) {
            return 'local_database_locked';
        }

        if (str_contains($message, 'readonly')
            || str_contains($message, 'read-only')
            || str_contains($message, 'attempt to write a readonly database')
            || str_contains($message, 'readonly database')) {
            return 'local_database_read_only';
        }

        if (str_contains($message, 'malformed')
            || str_contains($message, 'not a database')
            || str_contains($message, 'file is not a database')
            || str_contains($message, 'database disk image is malformed')) {
            return 'local_database_corrupt';
        }

        return 'local_database_unavailable';
    }

    private function localConfigReadFailureMessage(string $reason): string
    {
        return match ($reason) {
            'local_database_locked' => 'Local Orbit database is locked. Another Orbit process may be writing; try again.',
            'local_database_read_only' => 'Local Orbit database is read-only. Check the database file permissions.',
            'local_database_corrupt' => 'Local Orbit database file is corrupt or unreadable.',
            'settings_table_missing' => 'Local gateway settings table is missing. Run database migrations.',
            default => 'Local Orbit database is unavailable. Check the database file path and permissions.',
        };
    }

    private function persistPem(RootCaFetchResult $result): ?string
    {
        $path = storage_path('app/orbit/gateway-ca/orbit.crt');
        $dir = dirname($path);

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        if (File::put($path, $result->pem) === false) {
            return null;
        }

        return $path;
    }

    private function isAlreadyTrusted(TrustStoreInstaller $installer, string $pemPath, string $sha256): bool
    {
        $settings = LocalGatewaySettings::current();

        return $installer->isCaTrusted($pemPath, self::LABEL)
            && $settings->ca_sha256 === $sha256
            && $settings->ca_pem_path === $pemPath
            && $settings->trusted_at !== null;
    }

    private function trustedAt(): Carbon
    {
        $settings = LocalGatewaySettings::current();

        return $settings->trusted_at ?? now();
    }

    private function captureActivitySuccess(string $caSha256, string $status): void
    {
        $this->activityCaSha256 = $caSha256;
        $this->activityStatus = $status;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function jsonSuccess(array $data, array $meta): int
    {
        if (! $this->wantsJson()) {
            return self::SUCCESS;
        }

        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }
}
