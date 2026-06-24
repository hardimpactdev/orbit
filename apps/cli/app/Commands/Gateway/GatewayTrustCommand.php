<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\Concerns\WithStepTree;
use App\Commands\LocalOnlyCommand;
use App\Enums\Trust\TrustStoreInstallReason;
use App\Services\Gateway\FetchesGatewayRootCa;
use App\Services\OrbitConfigStore;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

final class GatewayTrustCommand extends LocalOnlyCommand
{
    use WithStepTree;

    private const string LABEL = 'orbit';

    #[\Override]
    protected $signature = 'gateway:trust {--json}';

    #[\Override]
    protected $description = 'Trust the gateway root CA in the local OS trust store.';

    public function handle(
        FetchesGatewayRootCa $fetch,
        OrbitConfigStore $configStore,
        TrustStoreInstaller $installer,
    ): int {
        $gateway = $this->resolveConfiguredGateway($configStore);

        if (array_key_exists('code', $gateway)) {
            /** @var array{code: string, message: string, meta: array<string, mixed>} $gateway */
            return $this->renderFailure($gateway['code'], $gateway['message'], $gateway['meta']);
        }

        /** @var array{url: string, ip: string} $gateway */
        if ($this->wantsJson()) {
            $outcome = $this->performTrust($gateway, $fetch, $configStore, $installer);

            if (array_key_exists('code', $outcome)) {
                /** @var array{code: string, message: string, meta: array<string, mixed>} $outcome */
                return $this->renderFailure($outcome['code'], $outcome['message'], $outcome['meta']);
            }

            /** @var array{data: array<string, mixed>, meta: array<string, mixed>} $outcome */
            return $this->renderSuccess($outcome['data'], $outcome['meta']);
        }

        return $this->renderTrustTree($gateway, $fetch, $configStore, $installer);
    }

    /**
     * @param  array{url: string, ip: string}  $gateway
     */
    private function renderTrustTree(
        array $gateway,
        FetchesGatewayRootCa $fetch,
        OrbitConfigStore $configStore,
        TrustStoreInstaller $installer,
    ): int {
        $gatewayUrl = $gateway['url'];
        $outcome = [];

        $result = $this->runStepOperation(
            'Trusting Gateway CA',
            [
                ['label' => 'Resolve configured gateway'],
                ['label' => 'Fetch trust material'],
                ['label' => 'Install local trust'],
                ['label' => 'Store trust metadata'],
            ],
            work: function () use ($gateway, $fetch, $configStore, $installer, &$outcome): void {
                $outcome = $this->performTrust($gateway, $fetch, $configStore, $installer);

                if (array_key_exists('code', $outcome)) {
                    /** @var array{code: string, message: string, meta: array<string, mixed>} $outcome */
                    throw new RuntimeException($outcome['message']);
                }
            },
            doneFooter: function () use ($gatewayUrl, &$outcome): string {
                return $this->trustStatus($outcome) === 'already_trusted'
                    ? "Gateway CA already trusted for {$gatewayUrl}."
                    : "Gateway CA trusted for {$gatewayUrl}.";
            },
        );

        return $result->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function trustStatus(array $outcome): ?string
    {
        $data = is_array($outcome['data'] ?? null) ? $outcome['data'] : [];
        $trust = is_array($data['gateway_trust'] ?? null) ? $data['gateway_trust'] : [];
        $status = $trust['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    /**
     * Perform the network fetch, local trust installation, and metadata write.
     *
     * @param  array{url: string, ip: string}  $gateway
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}|array{code: string, message: string, meta: array<string, mixed>}
     */
    private function performTrust(
        array $gateway,
        FetchesGatewayRootCa $fetch,
        OrbitConfigStore $configStore,
        TrustStoreInstaller $installer,
    ): array {
        $gatewayUrl = $gateway['url'];
        $gatewayIp = $gateway['ip'];
        $gatewayName = $configStore->activeGatewayName() ?? OrbitConfigStore::DEFAULT_GATEWAY_NAME;

        try {
            $caResult = $fetch->handle($gatewayIp);
        } catch (ConnectionException) {
            return [
                'code' => 'gateway_unavailable',
                'message' => "Could not fetch the gateway CA from {$gatewayUrl}.",
                'meta' => ['gateway_url' => $gatewayUrl, 'endpoint' => '/api/ca/root'],
            ];
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'HTTP') || str_contains($msg, 'Failed to fetch')) {
                return [
                    'code' => 'gateway_unavailable',
                    'message' => "Could not fetch the gateway CA from {$gatewayUrl}.",
                    'meta' => ['gateway_url' => $gatewayUrl, 'endpoint' => '/api/ca/root'],
                ];
            }

            return [
                'code' => 'node.gateway_api_error',
                'message' => 'Gateway returned invalid CA material.',
                'meta' => [
                    'gateway_url' => $gatewayUrl,
                    'endpoint' => '/api/ca/root',
                    'reason' => 'invalid_trust_material',
                ],
            ];
        }

        $pemPath = $this->persistPem($gatewayName, $caResult->pem, $configStore);

        if ($pemPath === null) {
            return [
                'code' => 'node.local_config_write_failed',
                'message' => 'Failed to store local gateway CA material.',
                'meta' => ['gateway_url' => $gatewayUrl, 'reason' => 'metadata_write_failed'],
            ];
        }

        if ($this->isAlreadyTrusted($installer, $configStore, $pemPath, $caResult->sha256)) {
            return [
                'data' => [
                    'gateway_trust' => [
                        'gateway_url' => $gatewayUrl,
                        'trusted' => true,
                        'status' => 'already_trusted',
                        'ca_sha256' => $caResult->sha256,
                    ],
                ],
                'meta' => ['trusted_at' => $this->trustedAt($configStore)],
            ];
        }

        try {
            $installer->trustCa($pemPath, self::LABEL);
        } catch (TrustStoreInstallException $e) {
            if ($e->reason === TrustStoreInstallReason::UnsupportedPlatform) {
                return [
                    'code' => 'node.unsupported_platform',
                    'message' => 'This platform does not support automatic gateway CA trust installation.',
                    'meta' => ['platform' => PHP_OS_FAMILY, 'reason' => 'unsupported_trust_store'],
                ];
            }

            return [
                'code' => 'node.local_config_write_failed',
                'message' => 'Failed to install the gateway CA into the local trust store.',
                'meta' => ['gateway_url' => $gatewayUrl, 'reason' => 'trust_store_failed'],
            ];
        }

        try {
            $config = $configStore->read();
            $name = $config['active_gateway'] ?? 'default';

            if (! is_string($name) || $name === '') {
                $name = 'default';
            }

            if (! isset($config['gateways']) || ! is_array($config['gateways'])) {
                $config['gateways'] = [];
            }

            if (! isset($config['gateways'][$name]) || ! is_array($config['gateways'][$name])) {
                $config['gateways'][$name] = [];
            }

            $config['gateways'][$name]['ca_sha256'] = $caResult->sha256;
            $config['gateways'][$name]['ca_pem_path'] = $pemPath;
            $config['gateways'][$name]['trusted_at'] = now()->toIso8601String();

            $configStore->save($config);
        } catch (\Throwable) {
            return [
                'code' => 'node.local_config_write_failed',
                'message' => 'Failed to write local trust metadata.',
                'meta' => ['gateway_url' => $gatewayUrl, 'reason' => 'metadata_write_failed'],
            ];
        }

        return [
            'data' => [
                'gateway_trust' => [
                    'gateway_url' => $gatewayUrl,
                    'trusted' => true,
                    'status' => 'trusted',
                    'ca_sha256' => $caResult->sha256,
                ],
            ],
            'meta' => ['trusted_at' => now()->toIso8601String()],
        ];
    }

    /**
     * @return array{url: string, ip: string}|array{code: string, message: string, meta: array<string, mixed>}
     */
    private function resolveConfiguredGateway(OrbitConfigStore $configStore): array
    {
        $active = $configStore->activeGateway();

        if ($active === null) {
            return [
                'code' => 'validation_failed',
                'message' => 'No gateway is configured. Run orbit gateway:add first.',
                'meta' => ['field' => 'gateway', 'reason' => 'missing'],
            ];
        }

        $url = is_string($active['url'] ?? null) ? $active['url'] : null;
        $ip = is_string($active['wireguard_ip'] ?? null) ? $active['wireguard_ip'] : null;

        if ($url === null || $url === '') {
            return [
                'code' => 'validation_failed',
                'message' => 'No gateway is configured. Run orbit gateway:add first.',
                'meta' => ['field' => 'gateway', 'reason' => 'missing'],
            ];
        }

        if ($ip === null || $ip === '') {
            $ip = ltrim(str_replace('https://', '', str_replace('http://', '', $url)), '/');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return [
                'code' => 'validation_failed',
                'message' => 'Configured gateway endpoint is invalid.',
                'meta' => ['field' => 'gateway', 'reason' => 'invalid'],
            ];
        }

        return ['url' => $url, 'ip' => $ip];
    }

    private function isAlreadyTrusted(
        TrustStoreInstaller $installer,
        OrbitConfigStore $configStore,
        string $pemPath,
        string $sha256,
    ): bool {
        $active = $configStore->activeGateway();

        if (! is_array($active)) {
            return false;
        }

        return (
            $installer->isCaTrusted($pemPath, self::LABEL)
            && ($active['ca_sha256'] ?? null) === $sha256
            && ($active['ca_pem_path'] ?? null) === $pemPath
            && isset($active['trusted_at'])
        );
    }

    private function trustedAt(OrbitConfigStore $configStore): string
    {
        $active = $configStore->activeGateway();
        $trustedAt = is_array($active) ? $active['trusted_at'] ?? null : null;

        return is_string($trustedAt) ? $trustedAt : now()->toIso8601String();
    }

    private function persistPem(string $gatewayName, string $pem, OrbitConfigStore $configStore): ?string
    {
        $configDir = dirname($configStore->path());
        $pemDir = "{$configDir}/gateways/{$gatewayName}";

        if (! is_dir($pemDir)) {
            if (! @mkdir($pemDir, 0700, true) && ! is_dir($pemDir)) {
                return null;
            }
        }

        $path = $pemDir.'/ca.pem';

        if (file_put_contents($path, $pem, LOCK_EX) === false) {
            return null;
        }

        @chmod($path, 0600);

        return $path;
    }
}
