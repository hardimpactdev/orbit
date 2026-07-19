<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\BootstrapGatewayCommand;
use App\Commands\Concerns\StreamsGatewayProgress;
use App\Exceptions\GatewayApiException;
use App\Exceptions\NodeWriteInputException;
use App\Exceptions\OrbitConfigStoreException;
use App\Services\GatewayApiClient;
use App\Services\Node\NodeBootstrapHostKeyMismatch;
use App\Services\Node\NodeBootstrapSshRunner;
use App\Services\Node\NodeBootstrapUnsupportedPlatform;
use App\Services\Node\NodeGatewayBootstrapper;
use App\Services\Node\NodeNewPayloadBuilder;
use App\Services\OrbitConfigStore;
use Orbit\Core\Progress\ProgressEventType;
use RuntimeException;

use function Laravel\Prompts\text;

/** @mago-expect lint:too-many-methods */
final class NodeNewCommand extends BootstrapGatewayCommand
{
    use StreamsGatewayProgress;

    private const int BOOTSTRAP_TIMEOUT_SECONDS = 900;

    #[\Override]
    protected $signature = 'node:new
        {name? : Registry name for the node}
        {--template= : Node template preset}
        {--operator : Create an operator client identity}
        {--roles= : Comma-separated canonical node roles}
        {--host= : SSH/bootstrap endpoint for gateway or workload nodes}
        {--operator-name= : Initiating operator node name for first-gateway bootstrap}
        {--operator-tld= : Initiating operator node TLD for first-gateway bootstrap}
        {--tld= : Explicit unique node TLD}
        {--user=root : Bootstrap SSH user for provisioning}
        {--gateway-endpoint= : WireGuard endpoint host this node should use to reach the gateway}
        {--ingress= : Existing ingress node for private app-prod placement}
        {--valkey-node= : Existing database node for websocket Valkey}
        {--postgres-node= : Existing database node for analytics PostgreSQL}
        {--postgres-process= : PostgreSQL process for analytics}
        {--clickhouse-node= : Existing database node for analytics ClickHouse}
        {--s3-data-path= : Host data path for the s3 role}
        {--host-key-fingerprint= : Expected SSH host key SHA256 fingerprint}
        {--self-grant= : Self-grant mode}
        {--self-grant-permissions= : Custom self-grant permissions}
        {--grant-to=* : Grant this node access to another node}
        {--grant-to-preset= : Preset for grant-to permissions}
        {--grant-to-permissions= : Custom permissions for grant-to}
        {--grant-from=* : Grant another node access to this node}
        {--grant-from-preset= : Preset for grant-from permissions}
        {--grant-from-permissions= : Custom permissions for grant-from}
        {--agent-tool=* : Agent tool to install}
        {--json : Output JSON}
        {--stream-json : Stream newline-delimited JSON progress frames}';

    #[\Override]
    protected $description = 'Create or provision a node in the Orbit fleet.';

    public function handle(
        NodeNewPayloadBuilder $payloadBuilder,
        NodeGatewayBootstrapper $bootstrapper,
        NodeBootstrapSshRunner $bootstrapSsh,
        OrbitConfigStore $configStore,
        GatewayApiClient $gatewayClient,
    ): int {
        $template = $this->stringOption('template');
        $firstGatewayBootstrap = $template === 'gateway' && ! $this->hasConfiguredGateway($configStore);
        $tld = $this->requiredOption('tld', 'Node TLD');
        $operatorName = $this->stringOption('operator-name');
        $operatorTld = $this->stringOption('operator-tld');

        if ($firstGatewayBootstrap && $this->allowsInteractiveInput()) {
            $operatorName ??= trim(text(label: 'Initiating operator node name', required: true));
            $operatorTld ??= trim(text(label: 'Initiating operator node TLD', required: true));
        }

        try {
            $payload = $payloadBuilder->build(
                name: $this->stringArgument('name'),
                template: $template,
                operator: (bool) $this->option('operator'),
                roles: $this->stringOption('roles'),
                host: $this->stringOption('host'),
                operatorName: $operatorName,
                operatorTld: $operatorTld,
                firstGatewayBootstrap: $firstGatewayBootstrap,
                tld: $tld,
                user: $this->stringOption('user'),
                gatewayEndpoint: $this->stringOption('gateway-endpoint'),
                ingressNode: $this->stringOption('ingress'),
                valkeyNode: $this->stringOption('valkey-node'),
                postgresNode: $this->stringOption('postgres-node'),
                postgresProcess: $this->stringOption('postgres-process'),
                clickhouseNode: $this->stringOption('clickhouse-node'),
                s3DataPath: $this->stringOption('s3-data-path'),
                hostKeyFingerprint: $this->stringOption('host-key-fingerprint'),
                selfGrant: $this->stringOption('self-grant'),
                selfGrantPermissions: $this->stringOption('self-grant-permissions'),
                grantTo: $this->arrayOption('grant-to'),
                grantToPreset: $this->stringOption('grant-to-preset'),
                grantToPermissions: $this->stringOption('grant-to-permissions'),
                grantFrom: $this->arrayOption('grant-from'),
                grantFromPreset: $this->stringOption('grant-from-preset'),
                grantFromPermissions: $this->stringOption('grant-from-permissions'),
                agentTools: $this->arrayOption('agent-tool'),
            );
        } catch (NodeWriteInputException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage(), $exception->meta);
        }

        if (($payload['template'] ?? null) === 'gateway' && ! $this->hasConfiguredGateway($configStore)) {
            if ($this->wantsStreamingJson()) {
                return $this->renderFailure(
                    'validation_failed',
                    '--stream-json is not supported for first-gateway bootstrap because that path does not expose gateway progress.',
                    ['field' => 'stream-json', 'reason' => 'unsupported_bootstrap_path'],
                );
            }

            $result = $bootstrapper->run($payloadBuilder->toGatewayArtisanArguments($payload, $this->wantsJson()));

            if ($result['output'] !== '') {
                $this->line($result['output']);
            }

            return $result['exit_code'] === self::SUCCESS ? self::SUCCESS : self::FAILURE;
        }

        if ($this->requiresClientBootstrap($payload)) {
            if (! $this->hasConfiguredGateway($configStore)) {
                return $this->renderFailure(
                    'gateway_unavailable',
                    'Gateway connection is required before creating workload nodes.',
                );
            }

            return $this->bootstrapWorkloadNode($payload, $bootstrapSsh, $gatewayClient);
        }

        return $this->streamProgress('/api/nodes', $payload, fn (
            ProgressEventType $type,
            array $payload,
        ): int => $this->renderProgressTerminalFrame($type, $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiresClientBootstrap(array $payload): bool
    {
        $workloadTemplates = [
            'app-development',
            'app-production',
            'database',
            'ingress',
            's3',
            'metrics',
            'analytics',
            'agent',
        ];

        return (
            is_string($payload['host'] ?? null)
            && $payload['host'] !== ''
            && ($payload['template'] ?? null) !== 'gateway'
            && (
                is_string($payload['template'] ?? null) && in_array($payload['template'], $workloadTemplates, true)
                || is_array($payload['roles'] ?? null)
                && $payload['roles'] !== []
            )
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function bootstrapWorkloadNode(
        array $payload,
        NodeBootstrapSshRunner $bootstrapSsh,
        GatewayApiClient $gatewayClient,
    ): int {
        $outputModeValidation = $this->validateProgressOutputMode();

        if ($outputModeValidation !== null) {
            return $outputModeValidation;
        }

        $preparePayload = $payload;
        unset($preparePayload['host_key_fingerprint']);

        try {
            $resumeResponse = $gatewayClient->withMinimumTimeout(self::BOOTSTRAP_TIMEOUT_SECONDS)->post(
                '/api/nodes/bootstrap/resume',
                $preparePayload,
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $resumedBootstrap = $this->resumedBootstrapPayload($resumeResponse);

        if ($resumedBootstrap !== null) {
            return $this->completeBootstrap($resumedBootstrap['id']);
        }

        if (! $this->bootstrapPreflightRequired($resumeResponse)) {
            return $this->renderFailure(
                'node.gateway_api_error',
                'Gateway node bootstrap resume response is incomplete.',
            );
        }

        try {
            $target = $bootstrapSsh->inspectTarget(
                host: (string) $payload['host'],
                user: is_string($payload['user'] ?? null) ? $payload['user'] : 'root',
                expectedFingerprint: $this->stringOption('host-key-fingerprint'),
            );
        } catch (NodeBootstrapHostKeyMismatch $exception) {
            return $this->renderFailure('node.host_key_mismatch', $exception->getMessage(), [
                'host' => $payload['host'],
                'step' => 'client_ssh_host_key',
            ]);
        } catch (NodeBootstrapUnsupportedPlatform $exception) {
            return $this->renderFailure('node.unsupported_platform', $exception->getMessage(), [
                'host' => $payload['host'],
                'platform' => $exception->platform,
                'architecture' => $exception->architecture,
                'step' => 'client_ssh_preflight',
            ]);
        } catch (RuntimeException $exception) {
            return $this->renderFailure('node.bootstrap_ssh_failed', $exception->getMessage(), [
                'host' => $payload['host'],
                'user' => $payload['user'] ?? 'root',
                'step' => 'client_ssh_preflight',
            ]);
        }

        $preparePayload = [
            ...$preparePayload,
            'platform' => $target['platform'],
            'architecture' => $target['architecture'],
        ];

        try {
            $response = $gatewayClient->withMinimumTimeout(self::BOOTSTRAP_TIMEOUT_SECONDS)->post(
                '/api/nodes/bootstrap',
                $preparePayload,
            );
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $bootstrap = $this->bootstrapPayload($response);

        if ($bootstrap === null) {
            return $this->renderFailure('node.gateway_api_error', 'Gateway node bootstrap response is incomplete.');
        }

        try {
            $result = $bootstrapSsh->run(
                host: $bootstrap['host'],
                user: $bootstrap['user'],
                script: $bootstrap['script'],
                expectedFingerprint: $this->stringOption('host-key-fingerprint'),
            );
        } catch (NodeBootstrapHostKeyMismatch $exception) {
            return $this->renderFailure('node.host_key_mismatch', $exception->getMessage(), [
                'host' => $bootstrap['host'],
                'step' => 'client_ssh_host_key',
            ]);
        } catch (RuntimeException $exception) {
            return $this->renderFailure('node.bootstrap_ssh_failed', $exception->getMessage(), [
                'host' => $bootstrap['host'],
                'user' => $bootstrap['user'],
                'step' => 'client_ssh_bootstrap',
            ]);
        }

        if (! $result->successful()) {
            return $this->renderFailure(
                'node.bootstrap_ssh_failed',
                "Client-local SSH bootstrap failed for {$bootstrap['user']}@{$bootstrap['host']}.",
                [
                    'host' => $bootstrap['host'],
                    'user' => $bootstrap['user'],
                    'step' => 'client_ssh_bootstrap',
                    'exit_code' => $result->exitCode(),
                    'error' => trim($result->errorOutput()) ?: trim($result->output()) ?: null,
                ],
            );
        }

        return $this->completeBootstrap($bootstrap['id']);
    }

    private function completeBootstrap(string $bootstrapId): int
    {
        return $this->streamProgress('/api/nodes/bootstrap/'.rawurlencode($bootstrapId).'/complete', [], fn (
            ProgressEventType $type,
            array $payload,
        ): int => $this->renderProgressTerminalFrame($type, $payload));
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function bootstrapPreflightRequired(array $response): bool
    {
        return ($response['success']['data']['preflight_required'] ?? null) === true;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{id: string, status: string}|null
     */
    private function resumedBootstrapPayload(array $response): ?array
    {
        $bootstrap = $response['success']['data']['bootstrap'] ?? null;

        if (! is_array($bootstrap) || ($bootstrap['ssh_required'] ?? null) !== false) {
            return null;
        }

        /** @var mixed $id */
        $id = $bootstrap['id'] ?? null;
        /** @var mixed $status */
        $status = $bootstrap['status'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($status) || $status === '') {
            return null;
        }

        return [
            'id' => $id,
            'status' => $status,
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{id: string, host: string, user: string, script: string}|null
     */
    private function bootstrapPayload(array $response): ?array
    {
        $bootstrap = $response['success']['data']['bootstrap'] ?? null;

        if (! is_array($bootstrap)) {
            return null;
        }

        /** @var mixed $id */
        $id = $bootstrap['id'] ?? null;
        /** @var mixed $host */
        $host = $bootstrap['host'] ?? null;
        /** @var mixed $user */
        $user = $bootstrap['user'] ?? null;
        /** @var mixed $script */
        $script = $bootstrap['script'] ?? null;

        if (
            ! is_string($id)
            || $id === ''
            || ! is_string($host)
            || $host === ''
            || ! is_string($user)
            || $user === ''
            || ! is_string($script)
            || $script === ''
        ) {
            return null;
        }

        return [
            'id' => $id,
            'host' => $host,
            'user' => $user,
            'script' => $script,
        ];
    }

    private function renderGatewayFailure(GatewayApiException $exception): int
    {
        if ($exception->hasGatewayError()) {
            return $this->renderFailure(
                $exception->gatewayErrorCode() ?? $exception->cliFailureCode(),
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                $exception->gatewayErrorMeta(),
            );
        }

        return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
    }

    private function hasConfiguredGateway(OrbitConfigStore $configStore): bool
    {
        /** @var mixed $configured */
        $configured = config('orbit.gateway.url');

        if (is_string($configured) && trim($configured) !== '') {
            return true;
        }

        try {
            $activeGateway = $configStore->activeGateway();
        } catch (OrbitConfigStoreException) {
            return false;
        }

        /** @var mixed $url */
        $url = is_array($activeGateway) ? $activeGateway['url'] ?? null : null;

        return is_string($url) && trim($url) !== '';
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function requiredOption(string $key, string $label): ?string
    {
        $value = $this->stringOption($key);

        if ($value !== null || ! $this->allowsInteractiveInput()) {
            return $value;
        }

        $resolved = trim(text(label: $label, required: true));

        return $resolved !== '' ? $resolved : null;
    }

    /**
     * @return list<string>
     */
    private function arrayOption(string $key): array
    {
        $value = $this->option($key);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn (mixed $item): bool => is_string($item) && $item !== ''));
    }
}
