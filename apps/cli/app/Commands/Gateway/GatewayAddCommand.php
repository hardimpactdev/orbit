<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\BootstrapGatewayCommand;
use App\Enums\Trust\TrustStoreInstallReason;
use App\Services\Gateway\FetchesGatewayRootCa;
use App\Services\Gateway\VerifiesGatewayIdentity;
use App\Services\OrbitConfigStore;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
use App\Services\WireGuard\ResolvesGatewayAddress;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

final class GatewayAddCommand extends BootstrapGatewayCommand
{
    private const string LABEL = 'orbit';

    #[\Override]
    protected $signature = 'gateway:add
        {gateway_ip? : The WireGuard IP of the gateway}
        {--name=default : Local gateway entry name}
        {--json}';

    #[\Override]
    protected $description = 'Trust the gateway CA and register the local node connection.';

    public function handle(
        FetchesGatewayRootCa $fetch,
        VerifiesGatewayIdentity $verifyIdentity,
        ResolvesGatewayAddress $resolver,
        OrbitConfigStore $configStore,
        TrustStoreInstaller $installer,
    ): int {
        $gatewayIp = $this->resolveGatewayIp($resolver);
        $gatewayName = $this->resolveGatewayName();

        if ($gatewayName === null) {
            return $this->renderFailure(
                'validation_failed',
                'Gateway name must be a local slug.',
                ['field' => 'name', 'reason' => 'invalid_name'],
            );
        }

        if ($gatewayIp === null || $gatewayIp === '') {
            return $this->renderFailure(
                'validation_failed',
                'Gateway IP is required when it cannot be derived from an active WireGuard network.',
                ['field' => 'gateway_ip', 'reason' => 'missing'],
            );
        }

        if (! $this->isValidWireGuardIp($gatewayIp)) {
            return $this->renderFailure(
                'validation_failed',
                'Gateway IP must be a valid Orbit WireGuard address.',
                ['field' => 'gateway_ip', 'reason' => 'invalid_ip'],
            );
        }

        if ($this->isConverged($gatewayName, $gatewayIp, $installer, $configStore)) {
            return $this->handleConverged($gatewayName, $gatewayIp, $verifyIdentity, $configStore);
        }

        try {
            $caResult = $fetch->handle($gatewayIp);
        } catch (ConnectionException) {
            return $this->renderFailure(
                'gateway_unavailable',
                "Could not fetch the gateway CA from {$gatewayIp}.",
                ['gateway_ip' => $gatewayIp, 'endpoint' => '/api/ca/root'],
            );
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'HTTP') || str_contains($msg, 'Failed to fetch')) {
                return $this->renderFailure(
                    'gateway_unavailable',
                    "Could not fetch the gateway CA from {$gatewayIp}.",
                    ['gateway_ip' => $gatewayIp, 'endpoint' => '/api/ca/root'],
                );
            }

            return $this->renderFailure(
                'node.gateway_api_error',
                'Gateway returned invalid CA material.',
                ['gateway_ip' => $gatewayIp, 'endpoint' => '/api/ca/root', 'reason' => 'invalid_trust_material'],
            );
        }

        $pemPath = $this->persistPem($gatewayName, $caResult->pem, $configStore);

        if ($pemPath === null) {
            return $this->renderFailure(
                'node.local_config_write_failed',
                'Failed to store local gateway configuration.',
                ['gateway_ip' => $gatewayIp],
            );
        }

        try {
            $installer->trustCa($pemPath, self::LABEL);
        } catch (TrustStoreInstallException $e) {
            if ($e->reason === TrustStoreInstallReason::UnsupportedPlatform) {
                return $this->renderFailure(
                    'node.unsupported_platform',
                    'This platform does not support automatic gateway CA trust installation.',
                    ['platform' => PHP_OS_FAMILY, 'reason' => 'unsupported_trust_store'],
                );
            }

            return $this->renderFailure(
                'node.local_config_write_failed',
                'Failed to install the gateway CA into the local trust store.',
                ['gateway_ip' => $gatewayIp],
            );
        }

        $verifyResult = $verifyIdentity->handle($gatewayIp, $pemPath);

        if (array_key_exists('code', $verifyResult)) {
            /** @var array{code: string, message: string, meta: array<string, mixed>} $verifyResult */
            return $this->renderFailure($verifyResult['code'], $verifyResult['message'], $verifyResult['meta']);
        }

        /** @var array{gateway_name: string, gateway_ip: string, gateway_status: string, gateway_platform: string, local_node_name: string, local_node_status: string, local_node_platform: string, local_node_wg_ip: string} $verifyResult */
        if (! $this->persistGatewayConfig($gatewayName, $gatewayIp, $caResult->sha256, $pemPath, $configStore)) {
            return $this->renderFailure(
                'node.local_config_write_failed',
                'Failed to store local gateway configuration.',
                ['gateway_ip' => $gatewayIp, 'gateway_name' => $gatewayName],
            );
        }

        return $this->renderSuccess($this->buildSuccessData($verifyResult, 'added', $gatewayIp, $gatewayName));
    }

    private function handleConverged(
        string $gatewayName,
        string $gatewayIp,
        VerifiesGatewayIdentity $verifyIdentity,
        OrbitConfigStore $configStore,
    ): int {
        $entry = $configStore->gatewayEntry($gatewayName);
        $pemPath = is_array($entry) ? (string) ($entry['ca_pem_path'] ?? '') : '';

        if ($pemPath === '' || ! is_file($pemPath)) {
            return $this->renderFailure(
                'node.local_config_write_failed',
                'Failed to store local gateway configuration.',
                ['gateway_ip' => $gatewayIp],
            );
        }

        $verifyResult = $verifyIdentity->handle($gatewayIp, $pemPath);

        if (array_key_exists('code', $verifyResult)) {
            /** @var array{code: string, message: string, meta: array<string, mixed>} $verifyResult */
            return $this->renderFailure($verifyResult['code'], $verifyResult['message'], $verifyResult['meta']);
        }

        /** @var array{gateway_name: string, gateway_ip: string, gateway_status: string, gateway_platform: string, local_node_name: string, local_node_status: string, local_node_platform: string, local_node_wg_ip: string} $verifyResult */
        if (! $configStore->setActiveGateway($gatewayName)) {
            return $this->renderFailure(
                'node.local_config_write_failed',
                'Failed to store local gateway configuration.',
                ['gateway_ip' => $gatewayIp, 'gateway_name' => $gatewayName],
            );
        }

        return $this->renderSuccess($this->buildSuccessData($verifyResult, 'converged', $gatewayIp, $gatewayName));
    }

    private function resolveGatewayIp(ResolvesGatewayAddress $resolver): ?string
    {
        $ip = $this->argument('gateway_ip');

        if (is_string($ip) && $ip !== '') {
            return $ip;
        }

        return $resolver->resolve();
    }

    private function resolveGatewayName(): ?string
    {
        $name = $this->option('name');

        if (! is_string($name) || trim($name) === '') {
            $name = OrbitConfigStore::DEFAULT_GATEWAY_NAME;
        }

        $name = trim($name);

        return OrbitConfigStore::isValidGatewayName($name) ? $name : null;
    }

    private function isValidWireGuardIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        return str_starts_with($ip, '10.6.');
    }

    private function isConverged(
        string $gatewayName,
        string $gatewayIp,
        TrustStoreInstaller $installer,
        OrbitConfigStore $configStore,
    ): bool {
        $active = $configStore->gatewayEntry($gatewayName);

        if (! is_array($active)) {
            return false;
        }

        if (($active['wireguard_ip'] ?? null) !== $gatewayIp) {
            return false;
        }

        $caSha256 = $active['ca_sha256'] ?? null;
        if (! is_string($caSha256) || $caSha256 === '') {
            return false;
        }

        $pemPath = $active['ca_pem_path'] ?? null;
        if (! is_string($pemPath) || $pemPath === '' || ! is_file($pemPath)) {
            return false;
        }

        $pem = file_get_contents($pemPath);
        if ($pem === false || $pem === '') {
            return false;
        }

        if (hash('sha256', $pem) !== $caSha256) {
            return false;
        }

        return $installer->isCaTrusted($pemPath, self::LABEL);
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

    private function persistGatewayConfig(
        string $gatewayName,
        string $gatewayIp,
        string $caSha256,
        string $pemPath,
        OrbitConfigStore $configStore,
    ): bool {
        try {
            $config = $configStore->read();
            if (! isset($config['gateways']) || ! is_array($config['gateways'])) {
                $config['gateways'] = [];
            }

            $config['active_gateway'] = $gatewayName;
            $config['gateways'][$gatewayName] = [
                'url' => "https://{$gatewayIp}",
                'wireguard_ip' => $gatewayIp,
                'ca_pem_path' => $pemPath,
                'ca_sha256' => $caSha256,
                'ca_fingerprint' => 'sha256:'.$caSha256,
                'timeout' => OrbitConfigStore::DEFAULT_TIMEOUT_SECONDS,
                'self_mode' => OrbitConfigStore::DEFAULT_SELF_MODE,
            ];
            $configStore->save($config);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array{gateway_name: string, gateway_ip: string, gateway_status: string, gateway_platform: string, local_node_name: string, local_node_status: string, local_node_platform: string, local_node_wg_ip: string}  $verifyResult
     * @return array<string, mixed>
     */
    private function buildSuccessData(array $verifyResult, string $action, string $gatewayIp, string $gatewayName): array
    {
        return [
            'result' => [
                'action' => $action,
            ],
            'local_gateway' => [
                'name' => $gatewayName,
                'active' => true,
            ],
            'gateway' => [
                'name' => $verifyResult['gateway_name'],
                'status' => $verifyResult['gateway_status'],
                'platform' => $verifyResult['gateway_platform'],
                'addresses' => [
                    'wireguard' => $gatewayIp,
                ],
            ],
            'local_node' => [
                'name' => $verifyResult['local_node_name'],
                'status' => $verifyResult['local_node_status'],
                'platform' => $verifyResult['local_node_platform'],
                'addresses' => [
                    'wireguard' => $verifyResult['local_node_wg_ip'],
                ],
            ],
            'local_onboarding' => [
                'gateway_trust' => $action === 'converged' ? 'already_trusted' : 'trusted',
                'gateway_config' => $action === 'converged' ? 'already_stored' : 'stored',
                'gateway_api' => 'verified',
            ],
        ];
    }
}
