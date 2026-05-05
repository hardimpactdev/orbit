<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\WireGuardPeer;
use App\Services\Gateway\GatewayRequestSender;
use App\Services\Nodes\NodeRegistryWriter;
use App\Services\OrbitHostInstaller;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
use App\Services\Trust\TrustStoreInstallReason;
use App\Services\WireGuard\WireGuardKeyGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

#[Signature('node:new
    {name? : Registry name for the node}
    {--role= : Node role: gateway, control, or app}
    {--host= : SSH/bootstrap endpoint for gateway or app nodes}
    {--control-name= : Initiating control-node name for first-gateway bootstrap}
    {--environment= : App-node environment: development or production}
    {--tld= : Development app-node TLD}
    {--ssh-user=root : SSH user for provisioning}
    {--json : Output JSON}')]
#[Description('Create or provision a node in the Orbit fleet')]
class NodeNewCommand extends Command
{
    private const string DEFAULT_RUNTIME_USER = 'orbit';

    private const string TRUST_LABEL = 'orbit';

    public function handle(
        OrbitHostInstaller $installer,
        NodeRegistryWriter $registryWriter,
        WireGuardKeyGenerator $wireGuardKeyGenerator,
    ): int {
        $callerRole = $this->callerRole();

        if ($callerRole === 'app') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control or gateway node.',
                meta: ['caller_role' => 'app'],
            );
        }

        $name = $this->stringArgument('name');
        $role = $this->stringOption('role');

        if ($name === null) {
            return $this->validationFailed('name', 'Node name is required.');
        }

        if (! $this->isValidNodeName($name)) {
            return $this->validationFailed('name', 'Node name must be a valid node name.');
        }

        if ($role === null) {
            return $this->validationFailed('role', 'Node role is required.');
        }

        if (! in_array($role, ['gateway', 'app', 'control'], true)) {
            return $this->validationFailed('role', 'Node role must be one of gateway, app, or control.');
        }

        $gatewayConfigured = $this->gatewayConfigured();

        if ($role === 'app') {
            $inputs = $this->resolveAppInputs();

            if (is_int($inputs)) {
                return $inputs;
            }

            if (! $gatewayConfigured) {
                return $this->failCommand(
                    code: 'gateway_unavailable',
                    message: 'Gateway connection is required before creating app or control nodes.',
                    meta: ['requested_role' => $role],
                );
            }

            if ($callerRole === 'control') {
                return $this->forwardAppNodeCreation($name, $inputs);
            }

            return $this->provisionAppNode($installer, $registryWriter, $name, $inputs);
        }

        if ($callerRole === 'control' && ! $gatewayConfigured && $role === 'control') {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required before creating app or control nodes.',
                meta: ['requested_role' => $role],
            );
        }

        if ($role === 'control') {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway forwarding is required before this node role can be created.',
                meta: ['requested_role' => $role],
            );
        }

        if ($gatewayConfigured || $callerRole === 'gateway') {
            if (
                $callerRole === 'control'
                && $gatewayConfigured
                && Node::query()->where('name', $name)->where('role', 'gateway')->where('status', 'active')->exists()
            ) {
                return $this->convergeFirstGateway($name);
            }

            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway forwarding is required before gateway convergence can run.',
                meta: ['requested_role' => $role],
            );
        }

        return $this->bootstrapFirstGateway($installer, $wireGuardKeyGenerator, $name);
    }

    private function convergeFirstGateway(string $name): int
    {
        $host = $this->stringOption('host');

        if ($host === null) {
            return $this->validationFailed('host', 'Host is required for gateway nodes.');
        }

        $controlName = $this->stringOption('control-name') ?? $this->defaultControlName();

        if ($controlName === null || ! $this->isValidNodeName($controlName)) {
            return $this->validationFailed('control_name', 'Control node name must be a valid node name.');
        }

        if ($controlName === $name) {
            return $this->validationFailed('control_name', 'Control node name must be different from gateway node name.');
        }

        $gateway = Node::query()
            ->where('name', $name)
            ->where('role', 'gateway')
            ->where('status', 'active')
            ->first();

        if (! $gateway instanceof Node || $gateway->host !== $host) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Gateway node '{$name}' is not compatible with this bootstrap request.",
                meta: ['name' => $name, 'host' => $host],
            );
        }

        $control = Node::query()
            ->where('name', $controlName)
            ->where('role', 'control')
            ->where('status', 'active')
            ->where('is_local', true)
            ->first();

        if (! $control instanceof Node) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Local control node '{$controlName}' is not fully onboarded.",
                meta: [
                    'host' => $host,
                    'step' => 'local_control_identity',
                    'error' => 'Local control node record is missing or inactive.',
                ],
            );
        }

        $settings = LocalGatewaySettings::current();

        if (! is_string($settings->ca_pem_path) || $settings->ca_pem_path === '' || ! File::exists($settings->ca_pem_path)) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Gateway CA trust material is missing locally.',
                meta: [
                    'host' => $host,
                    'step' => 'gateway_ca_trust',
                    'error' => 'Local gateway CA PEM file is missing.',
                ],
            );
        }

        $caCert = File::get($settings->ca_pem_path);
        $caSha256 = is_string($settings->ca_sha256) && $settings->ca_sha256 !== ''
            ? $settings->ca_sha256
            : hash('sha256', $caCert);

        $trustStatus = $this->ensureGatewayCaTrusted(
            host: $host,
            trustPath: $settings->ca_pem_path,
            caSha256: $caSha256,
        );

        if (is_int($trustStatus)) {
            return $trustStatus;
        }

        $settings->fill([
            'gateway_url' => $this->gatewayUrl($host),
            'gateway_wg_ip' => $gateway->wireguard_address,
            'ca_sha256' => $caSha256,
            'ca_pem_path' => $settings->ca_pem_path,
            'trusted_at' => $settings->trusted_at ?? now(),
        ])->save();

        $payload = $this->firstGatewayPayload(
            action: 'converged',
            provisioningStatus: 'already_provisioned',
            name: $name,
            host: $host,
            gatewayAddress: (string) $gateway->wireguard_address,
            controlName: $controlName,
            controlAddress: (string) $control->wireguard_address,
            onboarding: [
                'wireguard' => 'already_installed',
                'gateway_trust' => $trustStatus,
                'gateway_config' => 'already_stored',
                'gateway_api' => 'pending',
            ],
            gatewayTrust: [
                'gateway_url' => $this->gatewayUrl($host),
                'trusted' => true,
                'status' => $trustStatus,
                'ca_sha256' => $caSha256,
                'ca_pem_path' => $settings->ca_pem_path,
            ],
        );

        if ($this->wantsJson()) {
            return $this->jsonSuccess($payload);
        }

        $this->info("Gateway node {$name} already provisioned.");
        $this->line("Endpoint: {$host}");
        $this->line("Control node: {$controlName}");

        return self::SUCCESS;
    }

    /**
     * @param  array{host: string, environment: string, tld: ?string, sshUser: string}  $inputs
     */
    private function forwardAppNodeCreation(string $name, array $inputs): int
    {
        try {
            $response = GatewayRequestSender::make()->post('/api/nodes', [
                'name' => $name,
                'role' => 'app',
                'host' => $inputs['host'],
                'environment' => $inputs['environment'],
                'tld' => $inputs['tld'],
                'ssh_user' => $inputs['sshUser'],
            ]);
        } catch (RuntimeException $exception) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway API request failed.',
                meta: [
                    'requested_role' => 'app',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        if (! $response->isSuccess()) {
            return $this->failCommand(
                code: $response->errorCode() ?? 'gateway_unavailable',
                message: $response->errorMessage() ?? 'Gateway API request failed.',
                meta: $response->errorMeta(),
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess($response->data());
        }

        $this->info("Created app node {$name}.");

        return self::SUCCESS;
    }

    /**
     * @param  array{host: string, environment: string, tld: ?string, sshUser: string}  $inputs
     */
    private function provisionAppNode(OrbitHostInstaller $installer, NodeRegistryWriter $registryWriter, string $name, array $inputs): int
    {
        if (Node::query()->where('name', $name)->where('status', 'active')->exists()) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Node '{$name}' already exists.",
                meta: ['name' => $name],
            );
        }

        if ($inputs['tld'] !== null && Node::query()->where('tld', $inputs['tld'])->where('status', 'active')->exists()) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Development TLD '{$inputs['tld']}' is already assigned to another node.",
                meta: [
                    'field' => 'tld',
                    'value' => $inputs['tld'],
                ],
            );
        }

        $runtimeUser = self::DEFAULT_RUNTIME_USER;
        $installation = $installer->install($inputs['host'], $inputs['sshUser'], 'app', $runtimeUser);

        if (! $installation->successful) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "App host '{$inputs['host']}' could not complete Orbit installation.",
                meta: [
                    'host' => $inputs['host'],
                    'step' => 'install_orbit',
                    'error' => trim($installation->errorOutput) ?: null,
                ],
            );
        }

        $wireguardAddress = $this->nextWireguardAddress();
        $gatewayEndpoint = $this->gatewayEndpoint();

        $registryWriter->writeAppNode(
            name: $name,
            environment: $inputs['environment'],
            tld: $inputs['tld'],
            host: $inputs['host'],
            wireguardAddress: $wireguardAddress,
            gatewayEndpoint: $gatewayEndpoint,
            sshUser: $inputs['sshUser'],
            user: $runtimeUser,
        );

        $payload = [
            'result' => [
                'action' => 'created',
            ],
            'node' => [
                'name' => $name,
                'role' => 'app',
                'environment' => $inputs['environment'],
                'tld' => $inputs['tld'],
                'platform' => 'unknown',
                'addresses' => [
                    'wireguard' => $wireguardAddress,
                ],
                'status' => 'active',
            ],
            'provisioning' => [
                'transport' => 'ssh',
                'host' => $inputs['host'],
                'status' => 'complete',
            ],
            'next_steps' => [],
        ];

        if ($inputs['environment'] === 'development') {
            $payload['development_tld'] = [
                'tld' => $inputs['tld'],
                'gateway_dns' => [
                    'domain' => "*.{$inputs['tld']}",
                    'target' => $wireguardAddress,
                    'status' => 'configured',
                ],
            ];
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess($payload);
        }

        $this->info("Created app node {$name}.");
        $this->line("Endpoint: {$inputs['host']}");

        return self::SUCCESS;
    }

    private function bootstrapFirstGateway(
        OrbitHostInstaller $installer,
        WireGuardKeyGenerator $wireGuardKeyGenerator,
        string $name,
    ): int {
        $host = $this->stringOption('host');

        if ($host === null) {
            return $this->validationFailed('host', 'Host is required for gateway nodes.');
        }

        $controlName = $this->stringOption('control-name') ?? $this->defaultControlName();

        if ($controlName === null || ! $this->isValidNodeName($controlName)) {
            return $this->validationFailed('control_name', 'Control node name must be a valid node name.');
        }

        if ($controlName === $name) {
            return $this->validationFailed('control_name', 'Control node name must be different from gateway node name.');
        }

        $sshUser = $this->stringOption('ssh-user') ?? 'root';
        $runtimeUser = self::DEFAULT_RUNTIME_USER;
        $gatewayAddress = '10.6.0.2';
        $controlAddress = $this->nextWireguardAddress(excluding: [$gatewayAddress]);

        try {
            $gatewayKeys = $wireGuardKeyGenerator->generateKeyPair();
            $controlKeys = $wireGuardKeyGenerator->generateKeyPair();
        } catch (RuntimeException $exception) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Failed to generate WireGuard identity material.',
                meta: [
                    'host' => $host,
                    'step' => 'wireguard_identity',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        $identityJson = json_encode([
            'gateway' => [
                'public_key' => $gatewayKeys['public_key'],
                'private_key' => $gatewayKeys['private_key'],
            ],
            'control' => [
                'name' => $controlName,
                'wireguard_address' => $controlAddress,
                'public_key' => $controlKeys['public_key'],
                'private_key' => $controlKeys['private_key'],
            ],
        ], JSON_THROW_ON_ERROR);

        $installation = $installer->install($host, $sshUser, 'gateway', $runtimeUser);

        if (! $installation->successful) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Gateway host '{$host}' could not complete Orbit installation.",
                meta: [
                    'host' => $host,
                    'step' => 'install_orbit',
                    'error' => trim($installation->errorOutput) ?: null,
                ],
            );
        }

        $bootstrapCommand = sprintf(
            'cd %s && php artisan orbit:internal:bootstrap-gateway-local %s %s --identity-json=-',
            escapeshellarg("/home/{$runtimeUser}/orbit"),
            escapeshellarg($name),
            escapeshellarg($gatewayAddress),
        );

        $command = $sshUser === $runtimeUser
            ? $bootstrapCommand
            : sprintf('sudo su - %s -c %s', escapeshellarg($runtimeUser), escapeshellarg($bootstrapCommand));

        $bootstrap = Process::timeout(120)->input($identityJson)->run(sprintf(
            'ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new %s@%s %s',
            escapeshellarg($sshUser),
            escapeshellarg($host),
            escapeshellarg($command),
        ));

        if (! $bootstrap->successful()) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Gateway host '{$host}' could not bootstrap gateway identity.",
                meta: [
                    'host' => $host,
                    'step' => 'bootstrap_gateway_identity',
                    'error' => trim($bootstrap->errorOutput()) ?: null,
                ],
            );
        }

        $caCert = trim($bootstrap->output());

        if (! str_starts_with($caCert, '-----BEGIN CERTIFICATE-----') || ! str_contains($caCert, '-----END CERTIFICATE-----')) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Gateway host '{$host}' returned an invalid or empty CA certificate.",
                meta: [
                    'host' => $host,
                    'step' => 'bootstrap_gateway_identity',
                    'error' => 'Remote bootstrap did not output a valid PEM certificate.',
                ],
            );
        }

        if (openssl_x509_parse($caCert) === false) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Gateway host '{$host}' returned an unparsable CA certificate.",
                meta: [
                    'host' => $host,
                    'step' => 'bootstrap_gateway_identity',
                    'error' => 'Remote bootstrap output is not a valid X.509 certificate.',
                ],
            );
        }

        $trustPath = storage_path("app/orbit/trust/{$name}-ca.crt");
        File::ensureDirectoryExists(dirname($trustPath));

        if (! File::put($trustPath, $caCert)) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Failed to store gateway CA certificate locally.',
                meta: [
                    'host' => $host,
                    'step' => 'bootstrap_gateway_identity',
                    'error' => 'Trust file write failed.',
                ],
            );
        }

        $caSha256 = hash('sha256', $caCert);

        if (! $this->wantsJson()) {
            $this->line('○ Verify gateway CA trust');
        }

        $trustStatus = $this->ensureGatewayCaTrusted(
            host: $host,
            trustPath: $trustPath,
            caSha256: $caSha256,
        );

        if (is_int($trustStatus)) {
            return $trustStatus;
        }

        DB::transaction(function () use ($name, $host, $sshUser, $runtimeUser, $controlName, $gatewayAddress, $controlAddress, $controlKeys, $trustPath, $caSha256): void {
            Node::query()->where('is_local', true)->update(['is_local' => false]);

            Node::query()->updateOrCreate(
                ['name' => $name],
                [
                    'role' => 'gateway',
                    'environment' => null,
                    'tld' => null,
                    'platform' => 'unknown',
                    'host' => $host,
                    'wireguard_address' => $gatewayAddress,
                    'gateway_endpoint' => $host,
                    'ssh_user' => $sshUser,
                    'user' => $runtimeUser,
                    'orbit_path' => "/home/{$runtimeUser}/orbit",
                    'status' => 'active',
                    'is_local' => false,
                ],
            );

            $control = Node::query()->updateOrCreate(
                ['name' => $controlName],
                [
                    'role' => 'control',
                    'environment' => null,
                    'tld' => null,
                    'platform' => 'unknown',
                    'host' => '127.0.0.1',
                    'wireguard_address' => $controlAddress,
                    'gateway_endpoint' => $host,
                    'ssh_user' => get_current_user(),
                    'user' => get_current_user(),
                    'orbit_path' => base_path(),
                    'status' => 'active',
                    'is_local' => true,
                ],
            );

            WireGuardPeer::query()->firstOrCreate(
                ['node_id' => $control->id],
                [
                    'public_key' => $controlKeys['public_key'],
                    'private_key' => $controlKeys['private_key'],
                    'allowed_ips' => "{$controlAddress}/32",
                ],
            );

            LocalGatewaySettings::current()->fill([
                'gateway_url' => $this->gatewayUrl($host),
                'gateway_wg_ip' => $gatewayAddress,
                'ca_sha256' => $caSha256,
                'ca_pem_path' => $trustPath,
                'trusted_at' => now(),
            ])->save();
        });

        $payload = $this->firstGatewayPayload(
            action: 'created',
            provisioningStatus: 'complete',
            name: $name,
            host: $host,
            gatewayAddress: $gatewayAddress,
            controlName: $controlName,
            controlAddress: $controlAddress,
            onboarding: [
                'wireguard' => 'installed',
                'gateway_trust' => $trustStatus,
                'gateway_config' => 'stored',
                'gateway_api' => 'pending',
            ],
            gatewayTrust: [
                'gateway_url' => $this->gatewayUrl($host),
                'trusted' => true,
                'status' => $trustStatus,
                'ca_sha256' => $caSha256,
                'ca_pem_path' => $trustPath,
            ],
        );

        if ($this->wantsJson()) {
            return $this->jsonSuccess($payload);
        }

        $this->info("Created gateway node {$name}.");
        $this->line("Endpoint: {$host}");
        $this->line("Control node: {$controlName}");

        return self::SUCCESS;
    }

    /**
     * @param  array{wireguard: string, gateway_trust: string, gateway_config: string, gateway_api: string}  $onboarding
     * @param  array<string, mixed>  $gatewayTrust
     * @return array{
     *     result: array{action: string},
     *     node: array<string, mixed>,
     *     provisioning: array<string, string>,
     *     local_control_node: array<string, mixed>,
     *     local_onboarding: array<string, string>,
     *     gateway_trust: array<string, mixed>,
     *     next_steps: array<int, mixed>
     * }
     */
    private function firstGatewayPayload(
        string $action,
        string $provisioningStatus,
        string $name,
        string $host,
        string $gatewayAddress,
        string $controlName,
        string $controlAddress,
        array $onboarding,
        array $gatewayTrust,
    ): array {
        return [
            'result' => [
                'action' => $action,
            ],
            'node' => [
                'name' => $name,
                'role' => 'gateway',
                'environment' => null,
                'tld' => null,
                'platform' => 'unknown',
                'addresses' => [
                    'wireguard' => $gatewayAddress,
                    'gateway_endpoint' => $host,
                ],
                'status' => 'active',
            ],
            'provisioning' => [
                'transport' => 'ssh',
                'host' => $host,
                'status' => $provisioningStatus,
            ],
            'local_control_node' => [
                'name' => $controlName,
                'role' => 'control',
                'environment' => null,
                'tld' => null,
                'platform' => 'unknown',
                'addresses' => [
                    'wireguard' => $controlAddress,
                ],
                'status' => 'active',
            ],
            'local_onboarding' => $onboarding,
            'gateway_trust' => $gatewayTrust,
            'next_steps' => [],
        ];
    }

    private function ensureGatewayCaTrusted(string $host, string $trustPath, string $caSha256): string|int
    {
        try {
            $installer = app(TrustStoreInstaller::class);
        } catch (TrustStoreInstallException $e) {
            if ($e->reason === TrustStoreInstallReason::UnsupportedPlatform) {
                return $this->unsupportedTrustStore();
            }

            throw $e;
        } catch (RuntimeException) {
            return $this->unsupportedTrustStore();
        }

        $alreadyTrusted = $installer->isCaTrusted($trustPath, self::TRUST_LABEL)
            && LocalGatewaySettings::current()->ca_sha256 === $caSha256;

        if ($alreadyTrusted) {
            return 'already_trusted';
        }

        try {
            $installer->trustCa($trustPath, self::TRUST_LABEL);
        } catch (TrustStoreInstallException $e) {
            if ($e->reason === TrustStoreInstallReason::UnsupportedPlatform) {
                return $this->unsupportedTrustStore();
            }

            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Failed to install the gateway CA into the local trust store.',
                meta: [
                    'host' => $host,
                    'step' => 'gateway_ca_trust',
                    'error' => 'Trust store installation failed.',
                ],
            );
        }

        return 'trusted';
    }

    private function unsupportedTrustStore(): int
    {
        return $this->failCommand(
            code: 'node.unsupported_platform',
            message: 'This platform does not support automatic gateway CA trust installation.',
            meta: ['platform' => PHP_OS_FAMILY, 'reason' => 'unsupported_trust_store'],
        );
    }

    private function gatewayUrl(string $host): string
    {
        return str_starts_with($host, 'http') ? $host : "https://{$host}";
    }

    private function callerRole(): string
    {
        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        if (! is_string($localRole) || $localRole === '') {
            return 'control';
        }

        if (! in_array($localRole, ['gateway', 'app', 'control'], true)) {
            return 'unknown';
        }

        return $localRole;
    }

    private function gatewayConfigured(): bool
    {
        if (Node::query()->where('role', 'gateway')->where('status', 'active')->exists()) {
            return true;
        }

        return LocalGatewaySettings::query()
            ->whereNotNull('gateway_url')
            ->where('gateway_url', '!=', '')
            ->whereNotNull('ca_pem_path')
            ->where('ca_pem_path', '!=', '')
            ->exists();
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{host: string, environment: string, tld: ?string, sshUser: string}|int
     */
    private function resolveAppInputs(): array|int
    {
        $host = $this->stringOption('host');

        if ($host === null) {
            return $this->validationFailed('host', 'Host is required for app nodes.');
        }

        $environment = $this->stringOption('environment');

        if ($environment === null) {
            return $this->validationFailed('environment', 'Environment is required for app nodes.');
        }

        if (! in_array($environment, ['development', 'production'], true)) {
            return $this->validationFailed('environment', 'Environment must be one of development or production.');
        }

        $tld = $this->stringOption('tld');

        if ($environment === 'development') {
            if ($tld === null) {
                return $this->validationFailed('tld', 'Development app nodes require a TLD.');
            }

            if (! $this->isValidTld($tld)) {
                return $this->validationFailed('tld', 'TLD must be a lowercase DNS label without a leading dot.');
            }
        }

        if ($environment === 'production' && $tld !== null) {
            return $this->validationFailed('tld', 'Production app nodes do not use a development TLD.');
        }

        return [
            'host' => $host,
            'environment' => $environment,
            'tld' => $tld,
            'sshUser' => $this->stringOption('ssh-user') ?? 'root',
        ];
    }

    private function defaultControlName(): ?string
    {
        $hostname = gethostname();

        if (! is_string($hostname) || $hostname === '') {
            return null;
        }

        $short = explode('.', $hostname)[0] ?? '';
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $short));
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : null;
    }

    private function isValidNodeName(string $name): bool
    {
        return (bool) preg_match('/^[a-z](?:[a-z0-9-]*[a-z0-9])?$/', $name);
    }

    private function isValidTld(string $tld): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $tld);
    }

    private function gatewayEndpoint(): ?string
    {
        /** @var Node|null $gateway */
        $gateway = Node::query()
            ->where('role', 'gateway')
            ->where('status', 'active')
            ->orderByDesc('is_local')
            ->first();

        if (! $gateway instanceof Node) {
            return null;
        }

        return $gateway->wireguard_address ?? $gateway->gateway_endpoint ?? $gateway->host;
    }

    /**
     * @param  array<int, string>  $excluding
     */
    private function nextWireguardAddress(array $excluding = []): string
    {
        $used = Node::query()
            ->whereNotNull('wireguard_address')
            ->pluck('wireguard_address')
            ->all();

        $used = array_merge($used, $excluding);

        for ($octet = 3; $octet <= 254; $octet++) {
            $candidate = "10.6.0.{$octet}";

            if (! in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        throw new RuntimeException('No available WireGuard addresses remain in 10.6.0.0/24.');
    }

    private function validationFailed(string $field, string $message): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: $message,
            meta: ['field' => $field],
        );
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

        $this->error($message);

        return self::FAILURE;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }
}
