<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\LogsCommandActivity;
use App\Contracts\Loggable;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Services\Gateway\FetchGatewayRootCa;
use App\Services\Gateway\RootCaFetchResult;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
use App\Services\Trust\TrustStoreInstallReason;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;

#[Signature('gateway:trust
    {--json : Output JSON}')]
#[Description('Trust the gateway root CA in the local OS trust store')]
class GatewayTrustCommand extends Command implements Loggable
{
    use LogsCommandActivity;

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
            $this->line('┌ Trust Gateway CA');
            $this->line('○ Resolve configured gateway');
            $this->line('○ Fetch trust material');
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

            if (! $this->wantsJson()) {
                $this->line('○ Check local trust');
                $this->line("└ Gateway CA already trusted for {$gatewayUrl}");
                $this->line('');
                $this->line("Gateway CA already trusted for {$gatewayUrl}.");
            }

            return $this->jsonSuccess([
                'gateway_trust' => [
                    'gateway_url' => $gatewayUrl,
                    'trusted' => true,
                    'status' => 'already_trusted',
                    'ca_sha256' => $result->sha256,
                ],
            ], ['trusted_at' => $this->trustedAt()->toIso8601String()]);
        }

        if (! $this->wantsJson()) {
            $this->line('○ Install local trust');
            $this->line('○ Store trust metadata');
        }

        try {
            $installer->trustCa($pemPath, self::LABEL, $this->wantsJson() ? null : function (string $line): void {
                $this->line($line);
            });
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

        if (! $this->wantsJson()) {
            $this->line("└ Gateway CA trusted for {$gatewayUrl}");
            $this->line('');
            $this->line("Gateway CA trusted for {$gatewayUrl}.");
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
        $settings = LocalGatewaySettings::current();

        if ($settings->gateway_url !== null && $settings->gateway_url !== '') {
            return $this->normalizeEndpoint((string) $settings->gateway_url, (string) ($settings->gateway_wg_ip ?? $settings->gateway_url));
        }

        $gatewayNodes = Node::query()
            ->where('role', 'gateway')
            ->where('status', 'active')
            ->get();

        if ($gatewayNodes->isEmpty()) {
            return [
                'code' => 'validation_failed',
                'message' => 'No gateway is configured. Run orbit gateway:add first.',
                'meta' => ['field' => 'gateway', 'reason' => 'missing'],
            ];
        }

        if ($gatewayNodes->count() > 1) {
            return [
                'code' => 'local_context_invalid',
                'message' => 'Could not read local gateway settings.',
                'meta' => ['field' => 'gateway', 'reason' => 'settings_unreadable'],
            ];
        }

        $node = $gatewayNodes->first();
        $host = $node->host !== null && $node->host !== '' ? $node->host : $node->wireguard_address;

        if ($host === null || $host === '') {
            return [
                'code' => 'validation_failed',
                'message' => 'Configured gateway endpoint is invalid.',
                'meta' => ['field' => 'gateway', 'reason' => 'invalid'],
            ];
        }

        return $this->normalizeEndpoint($host, $node->wireguard_address ?? $host);
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
            && $settings->ca_sha256 === $sha256;
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
