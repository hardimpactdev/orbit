<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\HandlesPromptCancellation;
use App\Concerns\LogsCommandActivity;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Contracts\Loggable;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Models\LocalGatewaySettings;
use App\Services\Gateway\FetchGatewayRootCa;
use App\Services\Gateway\RootCaFetchResult;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
use App\Services\Trust\TrustStoreInstallReason;
use App\Services\WireGuard\WireGuardGatewayAddressResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use RuntimeException;

#[Signature('gateway:add
    {gateway_ip? : The WireGuard IP of the gateway}
    {--json : Output JSON}')]
#[Description('Trust the gateway CA and register the local node connection')]
class GatewayAddCommand extends Command implements Loggable
{
    use HandlesPromptCancellation;
    use LogsCommandActivity;
    use WithSpinner;
    use WithStepTree;

    private const string LABEL = 'orbit';

    private ?string $activityGatewayIp = null;

    private ?string $activityGatewayName = null;

    private ?string $activityLocalNodeName = null;

    private ?string $activityResult = null;

    private bool $progressTreeOpen = false;

    public function handle(FetchGatewayRootCa $fetch, WireGuardGatewayAddressResolver $gatewayAddressResolver): int
    {
        $this->bootActivityLog();

        try {
            return $this->executeGatewayAdd($fetch, $gatewayAddressResolver);
        } finally {
            $this->finishActivityLog();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        $gatewayIp = $this->activityGatewayIp;

        if ($gatewayIp === null) {
            $argument = $this->argument('gateway_ip');
            $gatewayIp = is_string($argument) && $argument !== '' ? $argument : null;
        }

        return array_filter([
            'gateway_ip' => $gatewayIp,
            'gateway_name' => $this->activityGatewayName,
            'local_node' => $this->activityLocalNodeName,
            'result' => $this->activityResult,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function description(): ?string
    {
        if ($this->activityResult === 'converged' && $this->activityGatewayIp !== null) {
            return "Gateway {$this->activityGatewayIp} already configured";
        }

        if ($this->activityResult === 'added' && $this->activityGatewayIp !== null) {
            return "Gateway {$this->activityGatewayIp} added";
        }

        return 'Gateway onboarding attempted';
    }

    private function executeGatewayAdd(FetchGatewayRootCa $fetch, WireGuardGatewayAddressResolver $gatewayAddressResolver): int
    {
        // 1. Resolve caller role before any input or side effects
        $callerRole = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';

        if ($callerRole !== 'control') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control node.',
                meta: ['caller_role' => $callerRole],
            );
        }

        // 2. Resolve gateway_ip
        $gatewayIp = $this->resolveGatewayIp($gatewayAddressResolver);

        if ($gatewayIp === null || $gatewayIp === '') {
            $hasJson = $this->input->hasParameterOption('--json', true);
            $isInteractive = ! $hasJson && $this->input->isInteractive();

            if ($isInteractive) {
                try {
                    $gatewayIp = trim($this->promptText(
                        label: 'Gateway IP',
                        required: true,
                        validate: fn (string $value): ?string => $this->validateGatewayIpPrompt($value),
                    ));
                } catch (PromptAborted) {
                    return $this->promptAborted();
                }
            } else {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Gateway IP is required when it cannot be derived from an active WireGuard network.',
                    meta: ['field' => 'gateway_ip', 'reason' => 'missing'],
                );
            }
        }

        // 3. Validate IP
        $this->activityGatewayIp = $gatewayIp;

        if (! $this->isValidWireGuardIp($gatewayIp)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Gateway IP must be a valid Orbit WireGuard address.',
                meta: ['field' => 'gateway_ip', 'reason' => 'invalid_ip'],
            );
        }

        $installer = $this->resolveTrustStoreInstaller();
        if (is_int($installer)) {
            return $installer;
        }

        // 4. Check convergence
        $isConverged = $this->isConverged($gatewayIp, $installer);

        // 5. If converged, verify /api/me still works and return
        if ($isConverged) {
            $pemPath = (string) LocalGatewaySettings::current()->ca_pem_path;

            if (! File::exists($pemPath)) {
                // CA file missing despite converged state; re-run full flow
                $isConverged = false;
            } else {
                $verifyResult = $this->verifyGatewayApi($gatewayIp, $pemPath);

                if (array_key_exists('code', $verifyResult)) {
                    return $this->failCommand(
                        code: $verifyResult['code'],
                        message: $verifyResult['message'],
                        meta: $verifyResult['meta'],
                    );
                }

                $resultData = $this->buildSuccessData($verifyResult, 'converged', $gatewayIp);
                $this->captureActivitySuccess($verifyResult, 'converged', $gatewayIp);

                if (! $this->wantsJson()) {
                    $this->renderConvergedTree($gatewayIp);
                }

                if ($this->wantsJson()) {
                    return $this->jsonSuccess($resultData);
                }

                return self::SUCCESS;
            }
        }

        // 6. Fetch gateway root CA
        $updateGatewayStep = null;
        $gatewayStepWidth = 0;

        if (! $this->wantsJson()) {
            $gatewaySteps = [
                ['label' => 'Resolve gateway'],
                ['label' => 'Fetch trust material'],
                ['label' => 'Trust gateway CA'],
                ['label' => 'Verify gateway API'],
                ['label' => 'Verify identity'],
                ['label' => 'Store local config'],
            ];
            $gatewayStepWidth = $this->stepTreeLabelWidth($gatewaySteps);
            $this->renderStepTree('Joining Gateway', $gatewaySteps);
            $updateGatewayStep = $this->stepTreeUpdater(count($gatewaySteps));
            $updateGatewayStep(0, $this->stepSuccess('Resolve gateway', $gatewayStepWidth, $gatewayIp));
            $this->progressTreeOpen = true;
        }

        try {
            $caResult = $fetch->handle($gatewayIp);
        } catch (RuntimeException|ConnectionException $e) {
            return $this->mapFetchExceptionToError($e, $gatewayIp);
        }

        if ($updateGatewayStep !== null) {
            $updateGatewayStep(1, $this->stepSuccess('Fetch trust material', $gatewayStepWidth, $caResult->sha256));
        }

        // 7. Persist CA PEM
        $pemPath = $this->persistPem($caResult);

        if ($pemPath === null) {
            return $this->failCommand(
                code: 'node.local_config_write_failed',
                message: 'Failed to store local gateway configuration.',
                meta: ['gateway_ip' => $gatewayIp],
            );
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
                message: 'Failed to store local gateway configuration.',
                meta: ['gateway_ip' => $gatewayIp],
            );
        }

        if ($updateGatewayStep !== null) {
            $updateGatewayStep(2, $this->stepSuccess('Trust gateway CA', $gatewayStepWidth, 'trusted'));
        }

        $verifyResult = $this->verifyGatewayApi($gatewayIp, $pemPath);

        if (array_key_exists('code', $verifyResult)) {
            return $this->failCommand(
                code: $verifyResult['code'],
                message: $verifyResult['message'],
                meta: $verifyResult['meta'],
            );
        }

        if ($updateGatewayStep !== null) {
            $updateGatewayStep(3, $this->stepSuccess('Verify gateway API', $gatewayStepWidth, 'verified'));
            $updateGatewayStep(4, $this->stepSuccess('Verify identity', $gatewayStepWidth, (string) ($verifyResult['local_node_name'] ?? 'verified')));
        }

        try {
            LocalGatewaySettings::current()->fill([
                'gateway_url' => "https://{$gatewayIp}",
                'gateway_wg_ip' => $gatewayIp,
                'ca_sha256' => $caResult->sha256,
                'ca_pem_path' => $pemPath,
                'trusted_at' => now(),
            ])->save();
        } catch (RuntimeException) {
            return $this->failCommand(
                code: 'node.local_config_write_failed',
                message: 'Failed to store local gateway configuration.',
                meta: ['gateway_ip' => $gatewayIp],
            );
        }

        if ($updateGatewayStep !== null) {
            $updateGatewayStep(5, $this->stepSuccess('Store local config', $gatewayStepWidth, 'stored'));
        }

        // 11. Build result data
        $resultData = $this->buildSuccessData($verifyResult, 'added', $gatewayIp);
        $this->captureActivitySuccess($verifyResult, 'added', $gatewayIp);

        // 12. Render output
        if (! $this->wantsJson()) {
            $gatewayName = $verifyResult['gateway_name'] ?? 'gateway';
            $localNodeName = $verifyResult['local_node_name'] ?? 'control';
            $localNodeRole = $verifyResult['local_node_role'] ?? 'control';
            $footer = "Joined '{$gatewayName}' as '{$localNodeName}' ({$localNodeRole})";
            $this->finishStepTree("{$footer}.");
            $this->progressTreeOpen = false;
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess($resultData);
        }

        return self::SUCCESS;
    }

    private function finishActivityLog(): void
    {
        try {
            $this->finalizeActivityLog();
        } catch (\Throwable) {
            // Activity logging must not change the documented gateway:add result.
        }
    }

    private function resolveGatewayIp(WireGuardGatewayAddressResolver $gatewayAddressResolver): ?string
    {
        $gatewayIp = $this->argument('gateway_ip');

        if (is_string($gatewayIp) && $gatewayIp !== '') {
            return $gatewayIp;
        }

        return $gatewayAddressResolver->resolve();
    }

    private function isValidWireGuardIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        // Orbit WireGuard network is 10.6.0.0/16
        return str_starts_with($ip, '10.6.');
    }

    private function validateGatewayIpPrompt(string $value): ?string
    {
        $gatewayIp = trim($value);

        if ($gatewayIp === '') {
            return 'Gateway IP is required.';
        }

        return $this->isValidWireGuardIp($gatewayIp)
            ? null
            : 'Gateway IP must be a valid Orbit WireGuard address.';
    }

    private function isConverged(string $gatewayIp, TrustStoreInstaller $installer): bool
    {
        $settings = LocalGatewaySettings::current();

        if ($settings->gateway_wg_ip !== $gatewayIp) {
            return false;
        }

        if ($settings->ca_sha256 === null || $settings->ca_sha256 === '') {
            return false;
        }

        if ($settings->ca_pem_path === null || $settings->ca_pem_path === '') {
            return false;
        }

        $pemPath = $settings->ca_pem_path;

        if (! File::exists($pemPath)) {
            return false;
        }

        $pem = File::get($pemPath);

        if ($pem === '') {
            return false;
        }

        if (hash('sha256', $pem) !== $settings->ca_sha256) {
            return false;
        }

        if (! $installer->isCaTrusted($pemPath, self::LABEL)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|array{code: string, message: string, meta: array<string, mixed>}
     */
    private function verifyGatewayApi(string $gatewayIp, string $pemPath): array
    {
        try {
            $response = (new GatewayConnector(
                baseUrl: "https://{$gatewayIp}",
                caPemPath: $pemPath,
            ))->send(new ShowGatewayIdentityRequest);
        } catch (\Throwable) {
            return [
                'code' => 'gateway_unavailable',
                'message' => "Gateway at {$gatewayIp} is unreachable.",
                'meta' => ['gateway_ip' => $gatewayIp],
            ];
        }

        $status = $response->status();

        if ($status === 403) {
            return [
                'code' => 'node.identity_unknown',
                'message' => "This peer is not registered on the gateway at {$gatewayIp}. Ask your admin to run `orbit node:new --role=control <name>` on the gateway first, then retry.",
                'meta' => ['gateway_ip' => $gatewayIp],
            ];
        }

        if (! $response->successful()) {
            return [
                'code' => 'gateway_unavailable',
                'message' => "Gateway at {$gatewayIp} returned HTTP {$status} for /api/me.",
                'meta' => ['gateway_ip' => $gatewayIp, 'status' => $status],
            ];
        }

        $identity = $response->dto();
        $self = $identity->self;
        $gateway = $identity->gateway;

        if (! is_array($self)) {
            return [
                'code' => 'node.gateway_api_error',
                'message' => "Gateway at {$gatewayIp} returned an invalid identity response.",
                'meta' => ['gateway_ip' => $gatewayIp, 'endpoint' => '/api/me'],
            ];
        }

        if (! is_array($gateway)) {
            $gateway = [
                'name' => 'gateway',
                'wg_ip' => $gatewayIp,
                'status' => 'active',
            ];
        }

        return [
            'gateway_name' => (string) ($gateway['name'] ?? 'gateway'),
            'gateway_ip' => $gatewayIp,
            'gateway_role' => (string) ($gateway['role'] ?? 'gateway'),
            'gateway_status' => (string) ($gateway['status'] ?? 'active'),
            'gateway_platform' => (string) ($gateway['platform'] ?? 'unknown'),
            'local_node_name' => (string) ($self['name'] ?? 'control'),
            'local_node_role' => (string) ($self['role'] ?? 'control'),
            'local_node_status' => (string) ($self['status'] ?? 'active'),
            'local_node_platform' => (string) ($self['platform'] ?? 'unknown'),
            'local_node_wg_ip' => (string) ($self['wg_ip'] ?? $self['addresses']['wireguard'] ?? ''),
        ];
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

    /**
     * @param  array<string, mixed>  $verifyResult
     * @return array<string, mixed>
     */
    private function buildSuccessData(array $verifyResult, string $action, string $gatewayIp): array
    {
        return [
            'result' => [
                'action' => $action,
            ],
            'gateway' => [
                'name' => $verifyResult['gateway_name'],
                'role' => $verifyResult['gateway_role'],
                'status' => $verifyResult['gateway_status'],
                'platform' => $verifyResult['gateway_platform'],
                'addresses' => [
                    'wireguard' => $gatewayIp,
                ],
            ],
            'local_node' => [
                'name' => $verifyResult['local_node_name'],
                'role' => $verifyResult['local_node_role'],
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

    private function renderConvergedTree(string $gatewayIp): void
    {
        $this->runStepTree(
            'Joining Gateway',
            [
                [
                    'label' => 'Resolve gateway',
                    'run' => fn (): string => $gatewayIp,
                ],
                [
                    'label' => 'Verify gateway API',
                    'run' => fn (): string => 'verified',
                ],
                [
                    'label' => 'Verify identity',
                    'run' => fn (): string => 'verified',
                ],
            ],
            doneFooter: "Gateway {$gatewayIp} is already configured.",
            failFooter: "Failed to verify gateway {$gatewayIp}.",
        );
    }

    /**
     * @param  array<string, mixed>  $verifyResult
     */
    private function captureActivitySuccess(array $verifyResult, string $result, string $gatewayIp): void
    {
        $this->activityGatewayIp = $gatewayIp;
        $this->activityGatewayName = (string) ($verifyResult['gateway_name'] ?? '');
        $this->activityLocalNodeName = (string) ($verifyResult['local_node_name'] ?? '');
        $this->activityResult = $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function jsonSuccess(array $data): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
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

        if ($this->progressTreeOpen) {
            $this->finishStepTree('Failed to join gateway.', success: false);
            $this->progressTreeOpen = false;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    private function resolveTrustStoreInstaller(): TrustStoreInstaller|int
    {
        try {
            return app(TrustStoreInstaller::class);
        } catch (TrustStoreInstallException $e) {
            if ($e->reason === TrustStoreInstallReason::UnsupportedPlatform) {
                return $this->unsupportedTrustStore();
            }

            throw $e;
        } catch (RuntimeException) {
            return $this->unsupportedTrustStore();
        }
    }

    private function unsupportedTrustStore(): int
    {
        return $this->failCommand(
            code: 'node.unsupported_platform',
            message: 'This platform does not support automatic gateway CA trust installation.',
            meta: ['platform' => PHP_OS_FAMILY, 'reason' => 'unsupported_trust_store'],
        );
    }

    private function promptAborted(): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: 'Operation cancelled.',
            meta: [],
        );
    }

    private function mapFetchExceptionToError(\Throwable $e, string $gatewayIp): int
    {
        if ($e instanceof ConnectionException || str_contains($e->getMessage(), 'HTTP')) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: "Could not fetch the gateway CA from {$gatewayIp}.",
                meta: ['gateway_ip' => $gatewayIp, 'endpoint' => '/api/ca/root'],
            );
        }

        return $this->failCommand(
            code: 'node.gateway_api_error',
            message: 'Gateway returned invalid CA material.',
            meta: ['gateway_ip' => $gatewayIp, 'endpoint' => '/api/ca/root', 'reason' => 'invalid_trust_material'],
        );
    }
}
