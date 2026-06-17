<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\BootstrapGatewayCommand;
use App\Commands\Concerns\StreamsGatewayProgress;
use App\Exceptions\GatewayApiException;
use App\Exceptions\NodeWriteInputException;
use App\Exceptions\OrbitConfigStoreException;
use App\Services\Node\NodeGatewayBootstrapper;
use App\Services\Node\NodeNewPayloadBuilder;
use App\Services\OrbitConfigStore;
use Orbit\Core\Progress\ProgressEventType;

final class NodeNewCommand extends BootstrapGatewayCommand
{
    use StreamsGatewayProgress;

    #[\Override]
    protected $signature = 'node:new
        {name? : Registry name for the node}
        {--template= : Node template preset}
        {--operator : Create an operator client identity}
        {--roles= : Comma-separated canonical node roles}
        {--host= : SSH/bootstrap endpoint for gateway or workload nodes}
        {--operator-name= : Initiating operator node name for first-gateway bootstrap}
        {--tld= : Development app-node TLD}
        {--user=root : Bootstrap SSH user for provisioning}
        {--gateway-endpoint= : WireGuard endpoint host this node should use to reach the gateway}
        {--ingress= : Existing ingress node for private app-prod placement}
        {--redis-node= : Existing database node for websocket Redis}
        {--postgres-node= : Existing database node for analytics PostgreSQL}
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
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Create or provision a node in the Orbit fleet.';

    public function handle(
        NodeNewPayloadBuilder $payloadBuilder,
        NodeGatewayBootstrapper $bootstrapper,
        OrbitConfigStore $configStore,
    ): int {
        try {
            $payload = $payloadBuilder->build(
                name: $this->stringArgument('name'),
                template: $this->stringOption('template'),
                operator: (bool) $this->option('operator'),
                roles: $this->stringOption('roles'),
                host: $this->stringOption('host'),
                operatorName: $this->stringOption('operator-name'),
                tld: $this->stringOption('tld'),
                user: $this->stringOption('user'),
                gatewayEndpoint: $this->stringOption('gateway-endpoint'),
                ingressNode: $this->stringOption('ingress'),
                redisNode: $this->stringOption('redis-node'),
                postgresNode: $this->stringOption('postgres-node'),
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
            $result = $bootstrapper->run($payloadBuilder->toGatewayArtisanArguments($payload, $this->wantsJson()));

            if ($result['output'] !== '') {
                $this->line($result['output']);
            }

            return $result['exit_code'] === self::SUCCESS ? self::SUCCESS : self::FAILURE;
        }

        return $this->streamProgress(
            '/api/nodes',
            $payload,
            fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload),
        );
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
        $configured = config('orbit.gateway.url');

        if (is_string($configured) && trim($configured) !== '') {
            return true;
        }

        try {
            $activeGateway = $configStore->activeGateway();
        } catch (OrbitConfigStoreException) {
            return false;
        }

        $url = is_array($activeGateway) ? ($activeGateway['url'] ?? null) : null;

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
