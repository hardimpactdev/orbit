<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\Nodes\NodeIdentityArtifact;
use App\Enums\AdoptAction;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Nodes\CreateNodeRequest;
use App\Http\Gateway\Responses\Nodes\NodeCreateResponse;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\WireGuardPeer;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\NodeIdentityArtifactProbe;
use App\Services\Nodes\NodeRegistryWriter;
use App\Services\Nodes\NodesProbe;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\OrbitHostInstaller;
use App\Services\Platform\PlatformDetector;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
use App\Services\Trust\TrustStoreInstallReason;
use App\Services\WireGuard\WireGuardInterfaceInstaller;
use App\Services\WireGuard\WireGuardKeyGenerator;
use App\Services\WireGuard\WireGuardPeerRealityProbe;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('node:new
    {name? : Registry name for the node}
    {--role=* : Initial hosted role. Repeatable: app-development, app-production, database. Gateway bootstrap uses gateway internally.}
    {--host= : SSH/bootstrap endpoint for gateway or app nodes}
    {--control-name= : Initiating control-node name for first-gateway bootstrap}
    {--environment= : App-node environment: development or production}
    {--tld= : Development app-node TLD}
    {--user=root : SSH user for provisioning}
    {--json : Output JSON}')]
#[Description('Create or provision a node in the Orbit fleet')]
class NodeNewCommand extends Command
{
    private const string DEFAULT_RUNTIME_USER = 'orbit';

    private const string TRUST_LABEL = 'orbit';

    public function handle(
        OrbitHostInstaller $installer,
        NodeRegistryWriter $registryWriter,
        NodeRoleAssignmentService $nodeRoleAssignmentService,
        WireGuardKeyGenerator $wireGuardKeyGenerator,
        PlatformDetector $platformDetector,
        WireGuardInterfaceInstaller $wireGuardInterfaceInstaller,
        NodesProbe $nodesProbe,
    ): int {
        $callerRole = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';

        $name = $this->resolveName();
        $requestedRoles = $this->resolveRequestedRoles();
        $role = $this->resolveRole();

        if ($name === null) {
            return $this->validationFailed('name', 'Node name is required.');
        }

        if (! $this->isValidNodeName($name)) {
            return $this->validationFailed('name', 'Node name must be a valid node name.');
        }

        if (is_int($requestedRoles)) {
            return $requestedRoles;
        }

        $gatewayConfigured = $this->gatewayConfigured();

        if ($requestedRoles['gateway']) {
            $role = 'gateway';
        }

        if ($requestedRoles['hosted'] !== [] && ! $requestedRoles['legacy_app']) {
            $inputs = $this->resolveHostedRoleInputs($requestedRoles['hosted']);

            if (is_int($inputs)) {
                return $inputs;
            }

            if (! $gatewayConfigured) {
                return $this->failCommand(
                    code: 'gateway_unavailable',
                    message: 'Gateway connection is required before creating app or control nodes.',
                    meta: ['requested_role' => $requestedRoles['hosted'][0]],
                );
            }

            if ($callerRole === 'control') {
                return $this->forwardHostedRoleNodeCreation($name, $requestedRoles['hosted'], $inputs);
            }

            if ($this->containsAppHostingRole($requestedRoles['hosted'])) {
                return $this->provisionAppNode(
                    installer: $installer,
                    registryWriter: $registryWriter,
                    nodesProbe: $nodesProbe,
                    roleAssignmentService: $nodeRoleAssignmentService,
                    name: $name,
                    inputs: [
                        'host' => $inputs['host'],
                        'environment' => $inputs['environment'] ?? 'production',
                        'tld' => $inputs['tld'],
                        'sshUser' => $inputs['sshUser'] ?? 'root',
                    ],
                    initialHostedRoles: $requestedRoles['hosted'],
                );
            }

            return $this->provisionHostedRoleNode(
                installer: $installer,
                registryWriter: $registryWriter,
                roleAssignmentService: $nodeRoleAssignmentService,
                name: $name,
                roles: $requestedRoles['hosted'],
                inputs: $inputs,
            );
        }

        if ($requestedRoles['legacy_app']) {
            $role = 'app';
        }

        if ($role === null) {
            $role = 'control';
        }

        if ($role === 'app') {
            $inputs = $this->resolveAppInputs();

            if (is_int($inputs)) {
                return $inputs;
            }

            if ($requestedRoles['legacy_app'] && ! $this->wantsJson()) {
                $this->warn('The legacy app role now maps to a hosted app role.');
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

            $legacyHostedRoles = $inputs['environment'] === 'development'
                ? [NodeRoleName::AppDevelopment->value]
                : [NodeRoleName::AppProduction->value];

            return $this->provisionAppNode(
                installer: $installer,
                registryWriter: $registryWriter,
                nodesProbe: $nodesProbe,
                roleAssignmentService: $nodeRoleAssignmentService,
                name: $name,
                inputs: $inputs,
                initialHostedRoles: $requestedRoles['legacy_app'] ? $legacyHostedRoles : $requestedRoles['hosted'],
            );
        }

        if ($callerRole === 'control' && ! $gatewayConfigured && $role === 'control') {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required before creating app or control nodes.',
                meta: ['requested_role' => $role],
            );
        }

        if ($role === 'control') {
            $forbiddenInput = $this->forbiddenControlInput();

            if ($forbiddenInput !== null) {
                return $this->validationFailed($forbiddenInput, 'Control nodes do not use SSH/bootstrap-only input.');
            }

            if ($callerRole === 'control') {
                return $this->forwardControlNodeEnrollment($name);
            }

            return $this->enrollControlNode($wireGuardKeyGenerator, $name);
        }

        if ($gatewayConfigured || $callerRole === 'gateway') {
            if (
                $callerRole === 'control'
                && $gatewayConfigured
                && $this->gatewayQuery()->where('name', $name)->exists()
            ) {
                return $this->convergeFirstGateway($name);
            }

            if ($callerRole === 'control' && $this->gatewayApiConfigured()) {
                return $this->forwardGatewayConvergence($name);
            }

            if ($callerRole === 'gateway') {
                return $this->convergeGatewayLocally($name);
            }

            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway forwarding is required before gateway convergence can run.',
                meta: ['requested_role' => $role],
            );
        }

        return $this->bootstrapFirstGateway($installer, $wireGuardKeyGenerator, $platformDetector, $wireGuardInterfaceInstaller, $name);
    }

    private function convergeFirstGateway(string $name): int
    {
        $host = $this->resolveHost('gateway');

        if ($host === null) {
            return $this->validationFailed('host', 'Host is required for gateway nodes.');
        }

        if (! $this->isValidHost($host)) {
            return $this->validationFailed('host', 'Host must be a valid IP address or dotted DNS name.');
        }

        $controlName = $this->resolveControlName($name);

        if ($controlName === null || ! $this->isValidNodeName($controlName)) {
            return $this->validationFailed('control_name', 'Control node name must be a valid node name.');
        }

        if ($controlName === $name) {
            return $this->validationFailed('control_name', 'Control node name must be different from gateway node name.');
        }

        $gateway = $this->gatewayQuery()
            ->where('name', $name)
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

        if (! $this->wantsJson()) {
            $this->line('○ Verify gateway API');
        }

        $apiVerification = $this->verifyGatewayApi((string) $gateway->wireguard_address, $settings->ca_pem_path);

        if (array_key_exists('code', $apiVerification)) {
            return $this->failCommand(
                code: $apiVerification['code'],
                message: $apiVerification['message'],
                meta: $apiVerification['meta'],
            );
        }

        $payload = $this->firstGatewayPayload(
            action: 'converged',
            provisioningTransport: 'none',
            provisioningStatus: 'already_provisioned',
            name: $name,
            host: $host,
            gatewayAddress: (string) $gateway->wireguard_address,
            controlName: $controlName,
            controlAddress: (string) $control->wireguard_address,
            gatewayPlatform: $gateway->platform ?? 'unknown',
            controlPlatform: $control->platform ?? 'unknown',
            onboarding: [
                'wireguard' => 'already_installed',
                'gateway_trust' => $trustStatus,
                'gateway_config' => 'already_stored',
                'gateway_api' => 'verified',
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

        $this->info('Gateway is already provisioned.');

        return self::SUCCESS;
    }

    /**
     * @param  array{host: string, environment: ?string, tld: ?string, sshUser: ?string}  $inputs
     */
    private function forwardHostedRoleNodeCreation(string $name, array $roles, array $inputs): int
    {
        try {
            /** @var NodeCreateResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new CreateNodeRequest(
                    name: $name,
                    role: $this->firstRole($roles),
                    roles: $roles,
                    host: $inputs['host'],
                    environment: null,
                    tld: $inputs['tld'],
                    user: $inputs['sshUser'],
                ))
                ->dto();
        } catch (GatewayApiException $exception) {
            return $this->failCommand(
                code: $exception->errorCode() ?? 'gateway_unavailable',
                message: $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Gateway API request failed.',
                meta: $exception->errorMeta(),
            );
        } catch (Throwable $exception) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway API request failed.',
                meta: [
                    'requested_role' => $this->firstRole($roles),
                    'error' => $exception->getMessage(),
                ],
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess($dto->data);
        }

        $this->info("Created node {$name}.");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $roles
     * @param  array{host: string, environment: ?string, tld: ?string, sshUser: ?string}  $inputs
     */
    private function provisionHostedRoleNode(
        OrbitHostInstaller $installer,
        NodeRegistryWriter $registryWriter,
        NodeRoleAssignmentService $roleAssignmentService,
        string $name,
        array $roles,
        array $inputs,
    ): int {
        $existing = Node::query()->where('name', $name)->first();

        if ($existing instanceof Node && $existing->status === 'active') {
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

        $requiresHostProvisioning = array_intersect($roles, [
            NodeRoleName::AppDevelopment->value,
            NodeRoleName::AppProduction->value,
        ]) !== [];

        $runtimeUser = self::DEFAULT_RUNTIME_USER;
        $wireguardAddress = $this->nextWireguardAddress();
        $gatewayEndpoint = $this->gatewayEndpoint();
        $platform = 'ubuntu';
        $host = $requiresHostProvisioning ? $inputs['host'] : '';
        $user = $requiresHostProvisioning ? $runtimeUser : self::DEFAULT_RUNTIME_USER;
        $orbitPath = $requiresHostProvisioning ? "/home/{$runtimeUser}/orbit" : "/home/{$runtimeUser}/orbit";

        if ($requiresHostProvisioning) {
            $sshUser = $inputs['sshUser'] ?? 'root';

            $installation = $installer->install($inputs['host'], $sshUser, $runtimeUser);

            if (! $installation->successful) {
                return $this->installerFailure(
                    role: 'app',
                    host: $inputs['host'],
                    sshUser: $sshUser,
                    errorOutput: $installation->errorOutput,
                );
            }

            $sshAuthorization = $this->authorizeRuntimeSshUser(
                host: $inputs['host'],
                sshUser: $sshUser,
                runtimeUser: $runtimeUser,
            );

            if (is_int($sshAuthorization)) {
                return $sshAuthorization;
            }
        }

        $node = $registryWriter->writeNodeIdentity(
            name: $name,
            legacyRole: in_array(NodeRoleName::AppDevelopment->value, $roles, true) || in_array(NodeRoleName::AppProduction->value, $roles, true)
                ? 'app'
                : 'control',
            environment: $this->legacyEnvironmentForRoles($roles),
            tld: $inputs['tld'],
            platform: $platform,
            host: $host,
            wireguardAddress: $wireguardAddress,
            gatewayEndpoint: $gatewayEndpoint,
            user: $user,
            orbitPath: $orbitPath,
        );

        $failedAssignment = null;

        foreach ($roles as $role) {
            $assignment = $roleAssignmentService->add($node, $role, $this->settingsForRole($role, $inputs['tld']));

            if ($assignment->status === NodeRoleStatus::Error->value) {
                $failedAssignment = $assignment;

                break;
            }
        }

        if ($failedAssignment instanceof NodeRoleAssignment) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Node '{$name}' created but hosted role '{$failedAssignment->role}' failed to converge.",
                meta: [
                    'node' => $name,
                    'role' => $failedAssignment->role,
                    'status' => $failedAssignment->status,
                    'settings' => $failedAssignment->settings ?? [],
                    'last_error' => $failedAssignment->last_error,
                ],
            );
        }

        $payload = [
            'result' => [
                'action' => 'created',
            ],
            'node' => [
                'name' => $name,
                'role' => $node->role,
                'environment' => $node->environment,
                'tld' => $node->tld,
                'platform' => $node->platform,
                'addresses' => [
                    'wireguard' => $wireguardAddress,
                ],
                'status' => 'active',
            ],
            'roles' => $node->fresh()->roleAssignments->map(fn ($assignment): array => [
                'role' => $assignment->role,
                'status' => $assignment->status,
                'settings' => $assignment->settings ?? [],
                'last_error' => $assignment->last_error,
            ])->values()->all(),
            'provisioning' => [
                'transport' => $requiresHostProvisioning ? 'ssh' : 'none',
                'host' => $requiresHostProvisioning ? $inputs['host'] : null,
                'status' => $requiresHostProvisioning ? 'complete' : 'created',
            ],
            'next_steps' => [],
        ];

        if (in_array(NodeRoleName::AppDevelopment->value, $roles, true)) {
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

        $this->info("Created node {$name}.");

        return self::SUCCESS;
    }

    /**
     * @param  array{host: string, environment: string, tld: ?string, sshUser: string}  $inputs
     */
    private function forwardAppNodeCreation(string $name, array $inputs): int
    {
        try {
            /** @var NodeCreateResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new CreateNodeRequest(
                    name: $name,
                    role: 'app',
                    roles: ['app'],
                    host: $inputs['host'],
                    environment: $inputs['environment'],
                    tld: $inputs['tld'],
                    user: $inputs['sshUser'],
                ))
                ->dto();
        } catch (GatewayApiException $exception) {
            return $this->failCommand(
                code: $exception->errorCode() ?? 'gateway_unavailable',
                message: $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Gateway API request failed.',
                meta: $exception->errorMeta(),
            );
        } catch (Throwable $exception) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway API request failed.',
                meta: [
                    'requested_role' => 'app',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess($dto->data);
        }

        $this->info("Created app node {$name}.");

        return self::SUCCESS;
    }

    private function forwardGatewayConvergence(string $name): int
    {
        $host = $this->resolveHost('gateway');

        if ($host === null) {
            return $this->validationFailed('host', 'Host is required for gateway nodes.');
        }

        if (! $this->isValidHost($host)) {
            return $this->validationFailed('host', 'Host must be a valid IP address or dotted DNS name.');
        }

        try {
            /** @var NodeCreateResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new CreateNodeRequest(
                    name: $name,
                    role: 'gateway',
                    roles: ['gateway'],
                    host: $host,
                    environment: null,
                    tld: null,
                    user: $this->resolveSshUser(),
                ))
                ->dto();
        } catch (GatewayApiException $exception) {
            return $this->failCommand(
                code: $exception->errorCode() ?? 'gateway_unavailable',
                message: $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Gateway API request failed.',
                meta: $exception->errorMeta(),
            );
        } catch (Throwable $exception) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway API request failed.',
                meta: [
                    'requested_role' => 'gateway',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess($dto->data);
        }

        $this->info('Gateway is already provisioned.');

        return self::SUCCESS;
    }

    private function convergeGatewayLocally(string $name): int
    {
        $host = $this->resolveHost('gateway');

        if ($host === null) {
            return $this->validationFailed('host', 'Host is required for gateway nodes.');
        }

        if (! $this->isValidHost($host)) {
            return $this->validationFailed('host', 'Host must be a valid IP address or dotted DNS name.');
        }

        $gateway = $this->gatewayQuery()
            ->where('name', $name)
            ->first();

        if (! $gateway instanceof Node || ! $this->gatewayHostMatches($gateway, $host)) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: 'Existing gateway is incompatible with the requested host or identity.',
                meta: ['name' => $name, 'host' => $host],
            );
        }

        $payload = $this->gatewayConvergencePayload($gateway, $host);

        if ($this->wantsJson()) {
            return $this->jsonSuccess($payload);
        }

        $this->info('Gateway is already provisioned.');

        return self::SUCCESS;
    }

    private function gatewayHostMatches(Node $gateway, string $host): bool
    {
        return $gateway->host === $host || $gateway->gateway_endpoint === $host;
    }

    /**
     * @return array<string, mixed>
     */
    private function gatewayConvergencePayload(Node $gateway, string $host): array
    {
        return [
            'result' => [
                'action' => 'converged',
            ],
            'node' => [
                'name' => $gateway->name,
                'role' => 'gateway',
                'environment' => null,
                'tld' => null,
                'platform' => $gateway->platform ?? 'unknown',
                'addresses' => [
                    'wireguard' => $gateway->wireguard_address,
                    'gateway_endpoint' => $gateway->gateway_endpoint ?? $gateway->host,
                ],
                'status' => 'active',
            ],
            'provisioning' => [
                'transport' => 'none',
                'host' => $host,
                'status' => 'already_provisioned',
            ],
            'next_steps' => [],
        ];
    }

    private function forwardControlNodeEnrollment(string $name): int
    {
        try {
            /** @var NodeCreateResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new CreateNodeRequest(
                    name: $name,
                    role: 'control',
                    roles: ['control'],
                    host: null,
                    environment: null,
                    tld: null,
                    user: null,
                ))
                ->dto();
        } catch (GatewayApiException $exception) {
            return $this->failCommand(
                code: $exception->errorCode() ?? 'gateway_unavailable',
                message: $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Gateway API request failed.',
                meta: $exception->errorMeta(),
            );
        } catch (Throwable $exception) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway API request failed.',
                meta: [
                    'requested_role' => 'control',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess($dto->data);
        }

        $this->info("Enrolled control node {$name}.");

        return self::SUCCESS;
    }

    private function enrollControlNode(WireGuardKeyGenerator $wireGuardKeyGenerator, string $name): int
    {
        $existing = Node::query()->where('name', $name)->first();

        if ($existing instanceof Node && $existing->role !== 'control') {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Node '{$name}' already exists with a different role.",
                meta: [
                    'name' => $name,
                    'existing_role' => $existing->role,
                    'requested_role' => 'control',
                ],
            );
        }

        $wireguardAddress = $existing instanceof Node && is_string($existing->wireguard_address) && $existing->wireguard_address !== ''
            ? $existing->wireguard_address
            : $this->nextWireguardAddress();

        $gateway = $this->gatewayQuery()->first();

        if (! $gateway instanceof Node) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Gateway identity is missing locally.',
                meta: [
                    'step' => 'gateway_identity',
                    'error' => 'No active gateway node record exists.',
                ],
            );
        }

        $gatewayPeer = WireGuardPeer::query()->where('node_id', $gateway->id)->first();

        if (! $gatewayPeer instanceof WireGuardPeer) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Gateway WireGuard peer material is missing locally.',
                meta: [
                    'step' => 'gateway_wireguard_identity',
                    'node' => $gateway->name,
                ],
            );
        }

        try {
            $keys = $wireGuardKeyGenerator->generateKeyPair();
        } catch (RuntimeException $exception) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Failed to generate WireGuard identity material.',
                meta: [
                    'node' => $name,
                    'step' => 'wireguard_identity',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        $node = Node::query()->updateOrCreate(
            ['name' => $name],
            [
                'role' => 'control',
                'environment' => null,
                'tld' => null,
                'platform' => 'unknown',
                'host' => $wireguardAddress,
                'wireguard_address' => $wireguardAddress,
                'gateway_endpoint' => $this->gatewayEndpoint(),
                'user' => self::DEFAULT_RUNTIME_USER,
                'orbit_path' => '/home/'.self::DEFAULT_RUNTIME_USER.'/orbit',
                'status' => 'active',
            ],
        );

        $peer = WireGuardPeer::query()->updateOrCreate(
            ['node_id' => $node->id],
            [
                'public_key' => $keys['public_key'],
                'private_key' => $keys['private_key'],
                'allowed_ips' => "{$wireguardAddress}/32",
            ],
        );

        $wireguardConfig = $this->controlWireGuardConfig(
            controlPrivateKey: $peer->private_key,
            controlWireguardAddress: $wireguardAddress,
            gatewayPublicKey: $gatewayPeer->public_key,
            gatewayWireguardAddress: (string) $gateway->wireguard_address,
            gatewayEndpoint: $gateway->gateway_endpoint ?? $gateway->host,
        );

        $payload = [
            'result' => [
                'action' => 'enrolled',
            ],
            'node' => [
                'name' => $name,
                'role' => 'control',
                'environment' => null,
                'tld' => null,
                'platform' => 'unknown',
                'addresses' => [
                    'wireguard' => $wireguardAddress,
                ],
                'status' => 'active',
            ],
            'provisioning' => [
                'transport' => 'wireguard',
                'host' => null,
                'status' => 'enrolled',
            ],
            'wireguard' => [
                'config' => $wireguardConfig,
            ],
            'next_steps' => [
                'Install the WireGuard configuration on the control node.',
                'Join the Orbit WireGuard network.',
                'Run `orbit gateway:add` on the control node.',
            ],
        ];

        if ($this->wantsJson()) {
            return $this->jsonSuccess($payload);
        }

        $this->info("Enrolled control node {$name}.");

        return self::SUCCESS;
    }

    /**
     * @param  array{host: string, environment: string, tld: ?string, sshUser: string}  $inputs
     * @param  list<string>  $initialHostedRoles
     */
    private function provisionAppNode(
        OrbitHostInstaller $installer,
        NodeRegistryWriter $registryWriter,
        NodesProbe $nodesProbe,
        NodeRoleAssignmentService $roleAssignmentService,
        string $name,
        array $inputs,
        array $initialHostedRoles = [],
    ): int {
        $existing = Node::query()->where('name', $name)->first();

        if (
            $existing instanceof Node
            && $existing->status === 'active'
            && $existing->role === 'app'
            && ! WireGuardPeer::query()->where('node_id', $existing->id)->exists()
        ) {
            return $this->adoptExistingAppNode($nodesProbe, $existing, $inputs, $roleAssignmentService, $initialHostedRoles);
        }

        if ($existing instanceof Node && $existing->status === 'active') {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Node '{$name}' already exists.",
                meta: ['name' => $name],
            );
        }

        if ($existing instanceof Node) {
            return $this->adoptExistingAppNode($nodesProbe, $existing, $inputs, $roleAssignmentService, $initialHostedRoles);
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

        $adoption = $this->materializeUnknownAppNode($nodesProbe, $registryWriter, $name, $inputs, $roleAssignmentService, $initialHostedRoles);

        if (is_int($adoption)) {
            return $adoption;
        }

        $wireguardAddress = $this->nextWireguardAddress();
        $developmentDnsMappingFailure = $this->guardDevelopmentDnsMappingAvailable($inputs['tld'], $wireguardAddress);

        if (is_int($developmentDnsMappingFailure)) {
            return $developmentDnsMappingFailure;
        }

        $runtimeUser = self::DEFAULT_RUNTIME_USER;
        $installation = $installer->install($inputs['host'], $inputs['sshUser'], $runtimeUser);

        if (! $installation->successful) {
            return $this->installerFailure(
                role: 'app',
                host: $inputs['host'],
                sshUser: $inputs['sshUser'],
                errorOutput: $installation->errorOutput,
            );
        }

        $sshAuthorization = $this->authorizeRuntimeSshUser(
            host: $inputs['host'],
            sshUser: $inputs['sshUser'],
            runtimeUser: $runtimeUser,
        );

        if (is_int($sshAuthorization)) {
            return $sshAuthorization;
        }

        $gatewayEndpoint = $this->gatewayEndpoint();

        $node = $registryWriter->writeAppNode(
            name: $name,
            environment: $inputs['environment'],
            tld: $inputs['tld'],
            host: $inputs['host'],
            wireguardAddress: $wireguardAddress,
            gatewayEndpoint: $gatewayEndpoint,
            sshUser: $inputs['sshUser'],
            user: $runtimeUser,
        );

        $roleAssignmentFailure = $this->ensureInitialHostedRoles($node, $roleAssignmentService, $initialHostedRoles, $inputs['tld']);

        if (is_int($roleAssignmentFailure)) {
            return $roleAssignmentFailure;
        }

        $developmentDns = app(DevelopmentDnsMappingEnactor::class)->converge($node);

        if ($initialHostedRoles !== [] && ($developmentDns['status'] ?? null) === 'already_configured') {
            $developmentDns['status'] = 'configured';
        }

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
                    'status' => $developmentDns['status'],
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

    /**
     * @param  array{host: string, environment: string, tld: ?string, sshUser: string}  $inputs
     * @param  list<string>  $initialHostedRoles
     */
    private function materializeUnknownAppNode(
        NodesProbe $nodesProbe,
        NodeRegistryWriter $registryWriter,
        string $name,
        array $inputs,
        NodeRoleAssignmentService $roleAssignmentService,
        array $initialHostedRoles = [],
    ): ?int {
        $candidate = new Node([
            'name' => $name,
            'role' => 'app',
            'environment' => $inputs['environment'],
            'tld' => $inputs['tld'],
            'platform' => 'unknown',
            'host' => $inputs['host'],
            'wireguard_address' => '',
            'gateway_endpoint' => $this->gatewayEndpoint(),
            'user' => $inputs['sshUser'],
            'orbit_path' => '/home/'.self::DEFAULT_RUNTIME_USER.'/orbit',
            'status' => 'active',
        ]);

        try {
            $artifact = app(NodeIdentityArtifactProbe::class)->read($candidate);
        } catch (Throwable) {
            return null;
        }

        if (! $this->identityArtifactMatchesAppRequest($artifact, $name)) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Host '{$inputs['host']}' has an incompatible Orbit node identity.",
                meta: [
                    'name' => $name,
                    'requested_role' => 'app',
                    'observed_name' => $artifact->name,
                    'observed_role' => $artifact->role,
                    'observed_local_role' => $artifact->localRole,
                    'observed_status' => $artifact->status,
                    'observed_platform' => $artifact->platform,
                ],
            );
        }

        $publicKey = $artifact->interfacePublicKey;
        $wireguardAddress = $artifact->wireguardAddress;

        if (is_string($wireguardAddress) && $wireguardAddress !== '') {
            $developmentDnsMappingFailure = $this->guardDevelopmentDnsMappingAvailable($inputs['tld'], $wireguardAddress);

            if (is_int($developmentDnsMappingFailure)) {
                return $developmentDnsMappingFailure;
            }
        }

        try {
            $peerReality = is_string($publicKey) && $publicKey !== ''
                ? app(WireGuardPeerRealityProbe::class)->peers()[$publicKey] ?? null
                : null;
        } catch (Throwable) {
            $peerReality = null;
        }

        if (
            ! is_string($publicKey)
            || $publicKey === ''
            || ! is_string($wireguardAddress)
            || $wireguardAddress === ''
            || $peerReality === null
            || count($peerReality->allowedAddresses) !== 1
            || $peerReality->allowedAddresses[0] !== $wireguardAddress
        ) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "App host '{$inputs['host']}' could not prove compatible WireGuard identity.",
                meta: [
                    'host' => $inputs['host'],
                    'step' => 'node_identity_adoption',
                    'error' => 'Identity artifact and live WireGuard peer reality did not match.',
                ],
            );
        }

        $node = $registryWriter->writeAppNode(
            name: $name,
            environment: $inputs['environment'],
            tld: $inputs['tld'],
            host: $inputs['host'],
            wireguardAddress: $wireguardAddress,
            gatewayEndpoint: $this->gatewayEndpoint(),
            sshUser: $inputs['sshUser'],
            user: self::DEFAULT_RUNTIME_USER,
        );

        $node->update([
            'platform' => $artifact->platform,
        ]);

        return $this->adoptExistingAppNode($nodesProbe, $node->refresh(), $inputs, $roleAssignmentService, $initialHostedRoles);
    }

    private function identityArtifactMatchesAppRequest(NodeIdentityArtifact $artifact, string $name): bool
    {
        return $artifact->name === $name
            && $artifact->role === 'app'
            && $artifact->localRole === 'app'
            && $artifact->status === 'active'
            && is_string($artifact->platform)
            && str_starts_with($artifact->platform, 'ubuntu_');
    }

    /**
     * @param  array{host: string, environment: string, tld: ?string, sshUser: string}  $inputs
     * @param  list<string>  $initialHostedRoles
     */
    private function adoptExistingAppNode(
        NodesProbe $nodesProbe,
        Node $node,
        array $inputs,
        NodeRoleAssignmentService $roleAssignmentService,
        array $initialHostedRoles = [],
    ): int {
        $incompatibleFields = [];

        if ($node->role !== 'app') {
            $incompatibleFields['role'] = $node->role;
        }

        if ($node->host !== $inputs['host']) {
            $incompatibleFields['host'] = $node->host;
        }

        if ($node->environment !== $inputs['environment']) {
            $incompatibleFields['environment'] = $node->environment;
        }

        if ($node->tld !== $inputs['tld']) {
            $incompatibleFields['tld'] = $node->tld;
        }

        if ($incompatibleFields !== []) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Node '{$node->name}' is not compatible with this adoption request.",
                meta: [
                    'name' => $node->name,
                    'requested_role' => 'app',
                    'incompatible_fields' => $incompatibleFields,
                ],
            );
        }

        if ($node->status === 'active') {
            $roleAssignmentFailure = $this->ensureInitialHostedRoles($node, $roleAssignmentService, $initialHostedRoles, $inputs['tld']);

            if (is_int($roleAssignmentFailure)) {
                return $roleAssignmentFailure;
            }

            $node->refresh();
        }

        $results = $nodesProbe->adopt($node, $nodesProbe->snapshotForAdopt($node));
        $hasConflict = false;
        $activated = false;

        foreach ($results as $result) {
            if ($result->action === AdoptAction::Conflict) {
                $hasConflict = true;
            }

            if (
                in_array($result->key, ['node.wireguard_peer_missing', 'node.wireguard_peer_extra'], true)
                && $result->action === AdoptAction::Updated
            ) {
                $activated = true;
            }
        }

        $node->refresh();

        if ($hasConflict || ! $activated || $node->status !== 'active') {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "App node '{$node->name}' could not be safely adopted.",
                meta: [
                    'node' => $node->name,
                    'step' => 'node_adoption',
                    'error' => 'Run `orbit doctor --fix --family=node --adopt --node='.$node->name.'` after resolving the reported node drift.',
                    'adoption_results' => array_map(fn ($result): array => $result->toArray(), $results),
                ],
            );
        }

        $roleAssignmentFailure = $this->ensureInitialHostedRoles($node, $roleAssignmentService, $initialHostedRoles, $inputs['tld']);

        if (is_int($roleAssignmentFailure)) {
            return $roleAssignmentFailure;
        }

        $developmentDns = app(DevelopmentDnsMappingEnactor::class)->converge($node);

        if ($initialHostedRoles !== [] && ($developmentDns['status'] ?? null) === 'already_configured') {
            $developmentDns['status'] = 'configured';
        }

        $payload = [
            'result' => [
                'action' => 'adopted',
            ],
            'node' => [
                'name' => $node->name,
                'role' => 'app',
                'environment' => $node->environment,
                'tld' => $node->tld,
                'platform' => $node->platform ?? 'unknown',
                'addresses' => [
                    'wireguard' => $node->wireguard_address,
                    'gateway_endpoint' => $node->gateway_endpoint,
                ],
                'status' => 'active',
            ],
            'provisioning' => [
                'transport' => 'none',
                'host' => $inputs['host'],
                'status' => 'adopted',
            ],
            'next_steps' => [],
        ];

        if ($node->environment === 'development') {
            $payload['development_tld'] = [
                'tld' => $node->tld,
                'gateway_dns' => [
                    'domain' => "*.{$node->tld}",
                    'target' => $node->wireguard_address,
                    'status' => $developmentDns['status'],
                ],
            ];
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess($payload);
        }

        $this->info("Adopted app node {$node->name}.");

        return self::SUCCESS;
    }

    private function authorizeRuntimeSshUser(string $host, string $sshUser, string $runtimeUser): ?int
    {
        $publicKey = $this->gatewaySshPublicKey();

        if (is_int($publicKey)) {
            return $publicKey;
        }

        $home = $runtimeUser === 'root' ? '/root' : "/home/{$runtimeUser}";
        $authorizedKeys = "{$home}/.ssh/authorized_keys";
        $script = sprintf(
            'sudo install -d -m 700 -o %1$s -g %1$s %2$s && sudo touch %3$s && sudo chown %1$s:%1$s %3$s && sudo chmod 600 %3$s && (sudo grep -qxF %4$s %3$s || printf "%%s\n" %4$s | sudo tee -a %3$s >/dev/null)',
            escapeshellarg($runtimeUser),
            escapeshellarg("{$home}/.ssh"),
            escapeshellarg($authorizedKeys),
            escapeshellarg($publicKey),
        );

        $authorization = Process::timeout(30)->run(sprintf(
            'ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new %s@%s %s',
            escapeshellarg($sshUser),
            escapeshellarg($host),
            escapeshellarg($script),
        ));

        if ($authorization->successful()) {
            return null;
        }

        return $this->failCommand(
            code: 'node.provisioning_incomplete',
            message: "Host '{$host}' could not authorize the steady-state SSH user.",
            meta: [
                'host' => $host,
                'step' => 'steady_state_ssh_authorization',
                'error' => trim($authorization->errorOutput()) ?: trim($authorization->output()) ?: null,
            ],
        );
    }

    private function ensureGatewayRuntimeDependencies(string $host, string $sshUser, string $runtimeUser): ?int
    {
        $script = sprintf(
            <<<'SCRIPT'
set -e
RUNTIME_USER=%s
if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1 || ! command -v sqlite3 >/dev/null 2>&1; then
    sudo apt-get -o DPkg::Lock::Timeout=300 update -qq
    if apt-cache show docker-compose-v2 >/dev/null 2>&1; then
        sudo DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y -qq docker.io docker-compose-v2 sqlite3
    elif apt-cache show docker-compose-plugin >/dev/null 2>&1; then
        sudo DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y -qq docker.io docker-compose-plugin sqlite3
    else
        sudo DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y -qq docker.io sqlite3
    fi
fi
if getent group docker >/dev/null 2>&1; then
    sudo usermod -aG docker "$RUNTIME_USER"
fi
sudo systemctl enable --now docker >/dev/null
sudo -H -u "$RUNTIME_USER" bash -lc 'docker compose version >/dev/null'
SCRIPT,
            escapeshellarg($runtimeUser),
        );

        $dependencies = Process::timeout(900)->run(sprintf(
            'ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new %s@%s %s',
            escapeshellarg($sshUser),
            escapeshellarg($host),
            escapeshellarg($script),
        ));

        if ($dependencies->successful()) {
            return null;
        }

        return $this->failCommand(
            code: 'node.provisioning_incomplete',
            message: "Gateway host '{$host}' could not prepare container runtime dependencies.",
            meta: [
                'host' => $host,
                'step' => 'gateway_runtime_dependencies',
                'error' => trim($dependencies->errorOutput()."\n".$dependencies->output()) ?: null,
            ],
        );
    }

    private function gatewaySshPublicKey(): string|int
    {
        $publicKey = Process::timeout(30)->run('ssh-keygen -y -f ~/.ssh/id_ed25519');

        if ($publicKey->successful() && trim($publicKey->output()) !== '') {
            return trim($publicKey->output());
        }

        return $this->failCommand(
            code: 'node.provisioning_incomplete',
            message: 'Gateway SSH identity is not available for steady-state access.',
            meta: [
                'step' => 'steady_state_ssh_authorization',
                'error' => trim($publicKey->errorOutput()) ?: trim($publicKey->output()) ?: null,
            ],
        );
    }

    private function bootstrapFirstGateway(
        OrbitHostInstaller $installer,
        WireGuardKeyGenerator $wireGuardKeyGenerator,
        PlatformDetector $platformDetector,
        WireGuardInterfaceInstaller $wireGuardInterfaceInstaller,
        string $name,
    ): int {
        $host = $this->resolveHost('gateway');

        if ($host === null) {
            return $this->validationFailed('host', 'Host is required for gateway nodes.');
        }

        if (! $this->isValidHost($host)) {
            return $this->validationFailed('host', 'Host must be a valid IP address or dotted DNS name.');
        }

        $controlName = $this->resolveControlName($name);

        if ($controlName === null || ! $this->isValidNodeName($controlName)) {
            return $this->validationFailed('control_name', 'Control node name must be a valid node name.');
        }

        if ($controlName === $name) {
            return $this->validationFailed('control_name', 'Control node name must be different from gateway node name.');
        }

        $sshUser = $this->resolveSshUser();
        $runtimeUser = self::DEFAULT_RUNTIME_USER;
        $gatewayAddress = '10.6.0.2';
        $controlAddress = $this->nextWireguardAddress(excluding: [$gatewayAddress]);

        try {
            $gatewayKeys = $wireGuardKeyGenerator->generateKeyPair();
            $controlKeys = $wireGuardKeyGenerator->generateKeyPair();
            $gatewayPreSharedKey = $this->generatePreSharedKey();
            $controlPreSharedKey = $this->generatePreSharedKey();
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
                'pre_shared_key' => $gatewayPreSharedKey,
            ],
            'control' => [
                'name' => $controlName,
                'wireguard_address' => $controlAddress,
                'public_key' => $controlKeys['public_key'],
                'private_key' => $controlKeys['private_key'],
                'pre_shared_key' => $controlPreSharedKey,
            ],
        ], JSON_THROW_ON_ERROR);

        $installation = $installer->install($host, $sshUser, $runtimeUser);

        if (! $installation->successful) {
            return $this->installerFailure(
                role: 'gateway',
                host: $host,
                sshUser: $sshUser,
                errorOutput: $installation->errorOutput,
            );
        }

        $sshAuthorization = $this->authorizeRuntimeSshUser(
            host: $host,
            sshUser: $sshUser,
            runtimeUser: $runtimeUser,
        );

        if (is_int($sshAuthorization)) {
            return $sshAuthorization;
        }

        $gatewayRuntimeDependencies = $this->ensureGatewayRuntimeDependencies(
            host: $host,
            sshUser: $sshUser,
            runtimeUser: $runtimeUser,
        );

        if (is_int($gatewayRuntimeDependencies)) {
            return $gatewayRuntimeDependencies;
        }

        $gatewayTld = $this->resolveGatewayTld();

        $bootstrapCommand = sprintf(
            'cd %s && php artisan orbit:internal:bootstrap-gateway-local %s %s --identity-json=- --public-host=%s --tld=%s --metadata-json',
            escapeshellarg("/home/{$runtimeUser}/orbit"),
            escapeshellarg($name),
            escapeshellarg($gatewayAddress),
            escapeshellarg($host),
            escapeshellarg($gatewayTld),
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
                    'error' => trim($bootstrap->errorOutput()."\n".$bootstrap->output()) ?: null,
                ],
            );
        }

        $bootstrapMetadata = $this->parseGatewayBootstrapMetadata($bootstrap->output(), $host);

        if (is_int($bootstrapMetadata)) {
            return $bootstrapMetadata;
        }

        $caCert = $bootstrapMetadata['ca_cert'];
        $wireguardServerPublicKey = $bootstrapMetadata['wireguard_server_public_key'];

        $gatewayPlatform = $this->detectRemotePlatform(
            host: $host,
            sshUser: $sshUser,
            runtimeUser: $runtimeUser,
        );

        if (is_int($gatewayPlatform)) {
            return $gatewayPlatform;
        }

        try {
            $controlPlatform = $platformDetector->detectLocal();
        } catch (RuntimeException $exception) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Failed to detect the local control platform.',
                meta: [
                    'host' => $host,
                    'step' => 'platform_detection',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        $trustPath = $this->localGatewayCaPath();
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

        if (! $this->wantsJson()) {
            $this->line('○ Install local WireGuard config');
        }

        try {
            $wireGuardInterfaceInstaller->install($this->controlWireGuardConfig(
                controlPrivateKey: $controlKeys['private_key'],
                controlWireguardAddress: $controlAddress,
                gatewayPublicKey: $wireguardServerPublicKey,
                gatewayWireguardAddress: $gatewayAddress,
                gatewayEndpoint: $host,
                preSharedKey: $controlPreSharedKey,
                allowedIps: '10.6.0.0/24',
            ));
        } catch (RuntimeException $exception) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Failed to install local WireGuard configuration.',
                meta: [
                    'host' => $host,
                    'step' => 'local_wireguard_install',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        DB::transaction(function () use ($name, $host, $runtimeUser, $controlName, $gatewayAddress, $gatewayPlatform, $controlAddress, $controlPlatform, $controlKeys, $controlPreSharedKey, $trustPath, $caSha256): void {
            $gateway = Node::query()->updateOrCreate(
                ['name' => $name],
                [
                    'role' => 'gateway',
                    'environment' => null,
                    'tld' => null,
                    'platform' => $gatewayPlatform,
                    'host' => $host,
                    'wireguard_address' => $gatewayAddress,
                    'gateway_endpoint' => $host,
                    'user' => $runtimeUser,
                    'orbit_path' => "/home/{$runtimeUser}/orbit",
                    'status' => 'active',
                ],
            );

            $gateway->roleAssignments()->firstOrCreate(
                ['role' => NodeRoleName::Gateway->value],
                [
                    'status' => NodeRoleStatus::Active->value,
                    'settings' => [],
                    'last_error' => null,
                    'converged_at' => now(),
                ],
            );

            $control = Node::query()->updateOrCreate(
                ['name' => $controlName],
                [
                    'role' => 'control',
                    'environment' => null,
                    'tld' => null,
                    'platform' => $controlPlatform,
                    'host' => '127.0.0.1',
                    'wireguard_address' => $controlAddress,
                    'gateway_endpoint' => $host,
                    'user' => get_current_user(),
                    'orbit_path' => base_path(),
                    'status' => 'active',
                ],
            );

            WireGuardPeer::query()->firstOrCreate(
                ['node_id' => $control->id],
                [
                    'public_key' => $controlKeys['public_key'],
                    'private_key' => $controlKeys['private_key'],
                    'pre_shared_key' => $controlPreSharedKey,
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

        if (! $this->wantsJson()) {
            $this->line('○ Verify gateway API');
        }

        $apiVerification = $this->verifyGatewayApi($gatewayAddress, $trustPath);

        if (array_key_exists('code', $apiVerification)) {
            return $this->failCommand(
                code: $apiVerification['code'],
                message: $apiVerification['message'],
                meta: $apiVerification['meta'],
            );
        }

        $payload = $this->firstGatewayPayload(
            action: 'created',
            provisioningTransport: 'ssh',
            provisioningStatus: 'complete',
            name: $name,
            host: $host,
            gatewayAddress: $gatewayAddress,
            controlName: $controlName,
            controlAddress: $controlAddress,
            gatewayPlatform: $gatewayPlatform,
            controlPlatform: $controlPlatform,
            onboarding: [
                'wireguard' => 'installed',
                'gateway_trust' => $trustStatus,
                'gateway_config' => 'stored',
                'gateway_api' => 'verified',
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

    private function controlWireGuardConfig(
        string $controlPrivateKey,
        string $controlWireguardAddress,
        string $gatewayPublicKey,
        string $gatewayWireguardAddress,
        string $gatewayEndpoint,
        ?string $preSharedKey = null,
        ?string $allowedIps = null,
    ): string {
        $lines = [
            '[Interface]',
            "PrivateKey = {$controlPrivateKey}",
            "Address = {$controlWireguardAddress}/24",
            '',
            '[Peer]',
            "PublicKey = {$gatewayPublicKey}",
        ];

        if ($preSharedKey !== null) {
            $lines[] = "PresharedKey = {$preSharedKey}";
        }

        return implode("\n", [
            ...$lines,
            'AllowedIPs = '.($allowedIps ?? "{$gatewayWireguardAddress}/32"),
            "Endpoint = {$gatewayEndpoint}:51820",
            'PersistentKeepalive = 25',
            '',
        ]);
    }

    private function generatePreSharedKey(): string
    {
        try {
            return base64_encode(random_bytes(32));
        } catch (Throwable $exception) {
            throw new RuntimeException('WireGuard pre-shared key generation failed.', previous: $exception);
        }
    }

    /**
     * @return array{ca_cert: string, wireguard_server_public_key: string}|int
     */
    private function parseGatewayBootstrapMetadata(string $output, string $host): array|int
    {
        $trimmed = trim($output);
        $caCert = $trimmed;
        $wireguardServerPublicKey = null;

        if (str_starts_with($trimmed, '{')) {
            try {
                $metadata = json_decode($trimmed, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return $this->failCommand(
                    code: 'node.provisioning_incomplete',
                    message: "Gateway host '{$host}' returned invalid bootstrap metadata.",
                    meta: [
                        'host' => $host,
                        'step' => 'bootstrap_gateway_identity',
                        'error' => 'Remote bootstrap metadata was not valid JSON.',
                    ],
                );
            }

            if (! is_array($metadata)) {
                return $this->failCommand(
                    code: 'node.provisioning_incomplete',
                    message: "Gateway host '{$host}' returned invalid bootstrap metadata.",
                    meta: [
                        'host' => $host,
                        'step' => 'bootstrap_gateway_identity',
                        'error' => 'Remote bootstrap metadata was not a JSON object.',
                    ],
                );
            }

            $caCert = is_string($metadata['ca_cert'] ?? null) ? trim($metadata['ca_cert']) : '';
            $wireguardServerPublicKey = is_string($metadata['wireguard_server_public_key'] ?? null)
                ? trim($metadata['wireguard_server_public_key'])
                : null;
        }

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

        if ($wireguardServerPublicKey === null || $wireguardServerPublicKey === '') {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Gateway host '{$host}' did not return WireGuard server identity metadata.",
                meta: [
                    'host' => $host,
                    'step' => 'bootstrap_gateway_identity',
                    'error' => 'Remote bootstrap metadata did not include wireguard_server_public_key.',
                ],
            );
        }

        return [
            'ca_cert' => $caCert,
            'wireguard_server_public_key' => $wireguardServerPublicKey,
        ];
    }

    /**
     * @param  array{wireguard: string, gateway_trust: string, gateway_config: string, gateway_api: string}  $onboarding
     * @param  array<string, mixed>  $gatewayTrust
     * @return array{
     *     result: array{action: string},
     *     node: array<string, mixed>,
     *     provisioning: array{transport: string, host: string, status: string},
     *     local_control_node: array<string, mixed>,
     *     local_onboarding: array<string, string>,
     *     gateway_trust: array<string, mixed>,
     *     next_steps: array<int, mixed>
     * }
     */
    private function firstGatewayPayload(
        string $action,
        string $provisioningTransport,
        string $provisioningStatus,
        string $name,
        string $host,
        string $gatewayAddress,
        string $controlName,
        string $controlAddress,
        string $gatewayPlatform,
        string $controlPlatform,
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
                'platform' => $gatewayPlatform,
                'addresses' => [
                    'wireguard' => $gatewayAddress,
                    'gateway_endpoint' => $host,
                ],
                'status' => 'active',
            ],
            'provisioning' => [
                'transport' => $provisioningTransport,
                'host' => $host,
                'status' => $provisioningStatus,
            ],
            'local_control_node' => [
                'name' => $controlName,
                'role' => 'control',
                'environment' => null,
                'tld' => null,
                'platform' => $controlPlatform,
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

    private function detectRemotePlatform(string $host, string $sshUser, string $runtimeUser): string|int
    {
        $detectCommand = sprintf(
            'cd %s && php artisan orbit:internal:detect-platform --update-local-node',
            escapeshellarg("/home/{$runtimeUser}/orbit"),
        );

        $command = $sshUser === $runtimeUser
            ? $detectCommand
            : sprintf('sudo su - %s -c %s', escapeshellarg($runtimeUser), escapeshellarg($detectCommand));

        $detection = Process::timeout(30)->run(sprintf(
            'ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new %s@%s %s',
            escapeshellarg($sshUser),
            escapeshellarg($host),
            escapeshellarg($command),
        ));

        if (! $detection->successful()) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Gateway host '{$host}' could not detect its platform.",
                meta: [
                    'host' => $host,
                    'step' => 'platform_detection',
                    'error' => trim($detection->errorOutput()) ?: trim($detection->output()) ?: null,
                ],
            );
        }

        $platform = trim($detection->output());

        if ($platform === '') {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Gateway host '{$host}' returned an empty platform identifier.",
                meta: [
                    'host' => $host,
                    'step' => 'platform_detection',
                    'error' => 'Remote platform detection did not output a platform identifier.',
                ],
            );
        }

        return $platform;
    }

    /**
     * @return array{}|array{code: string, message: string, meta: array<string, mixed>}
     */
    private function verifyGatewayApi(string $gatewayIp, string $pemPath): array
    {
        try {
            $response = new GatewayConnector(
                baseUrl: "https://{$gatewayIp}",
                caPemPath: $pemPath,
            )->send(new ShowGatewayIdentityRequest);
        } catch (Throwable) {
            return [
                'code' => 'gateway_unavailable',
                'message' => "Gateway at {$gatewayIp} is unreachable.",
                'meta' => [
                    'gateway_ip' => $gatewayIp,
                    'endpoint' => '/api/me',
                ],
            ];
        }

        $status = $response->status();

        if (! $response->successful()) {
            return [
                'code' => 'node.gateway_api_error',
                'message' => "Gateway at {$gatewayIp} returned HTTP {$status} for /api/me.",
                'meta' => [
                    'gateway_ip' => $gatewayIp,
                    'status' => $status,
                    'endpoint' => '/api/me',
                ],
            ];
        }

        $self = $response->dto()->self;

        if (! is_array($self)) {
            return [
                'code' => 'node.gateway_api_error',
                'message' => "Gateway at {$gatewayIp} returned an invalid identity response.",
                'meta' => [
                    'gateway_ip' => $gatewayIp,
                    'endpoint' => '/api/me',
                ],
            ];
        }

        return [];
    }

    private function unsupportedTrustStore(): int
    {
        return $this->failCommand(
            code: 'node.unsupported_platform',
            message: 'This platform does not support automatic gateway CA trust installation.',
            meta: ['platform' => PHP_OS_FAMILY, 'reason' => 'unsupported_trust_store'],
        );
    }

    private function installerFailure(string $role, string $host, string $sshUser, string $errorOutput): int
    {
        $error = trim($errorOutput);

        if ($this->isSshAuthorizationFailure($error)) {
            return $this->failCommand(
                code: 'authorization_failed',
                message: "Gateway cannot SSH to {$sshUser}@{$host}.",
                meta: [
                    'host' => $host,
                    'user' => $sshUser,
                    'step' => 'ssh_authorization',
                    'error' => $error !== '' ? $error : null,
                ],
            );
        }

        return $this->failCommand(
            code: 'node.provisioning_incomplete',
            message: ucfirst($role)." host '{$host}' could not complete Orbit installation.",
            meta: [
                'host' => $host,
                'step' => 'install_orbit',
                'error' => $error !== '' ? $error : null,
            ],
        );
    }

    private function isSshAuthorizationFailure(string $error): bool
    {
        return str_contains($error, 'Permission denied')
            || str_contains($error, 'publickey')
            || str_contains($error, 'Authentication failed');
    }

    private function gatewayUrl(string $host): string
    {
        return str_starts_with($host, 'http') ? $host : "https://{$host}";
    }

    private function localGatewayCaPath(): string
    {
        return storage_path('app/orbit/gateway-ca/orbit.crt');
    }

    private function gatewayConfigured(): bool
    {
        if ($this->gatewayQuery()->exists()) {
            return true;
        }

        return $this->gatewayApiConfigured();
    }

    private function gatewayApiConfigured(): bool
    {
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

    private function resolveGatewayTld(): string
    {
        $value = $this->stringOption('tld');

        return $value ?? 'gateway';
    }

    private function resolveName(): ?string
    {
        $name = $this->stringArgument('name');

        if ($name !== null) {
            return $name;
        }

        if (! $this->isInteractiveInput()) {
            return null;
        }

        return trim(text(
            label: 'Node name',
            required: true,
            validate: fn (string $value): ?string => $this->validatePromptNodeName($value),
        ));
    }

    private function resolveRole(): ?string
    {
        $roles = $this->roleOptions();

        return $roles[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function roleOptions(): array
    {
        $value = $this->option('role');

        if (is_string($value) && $value !== '') {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn ($role): bool => is_string($role) && $role !== ''));
    }

    /**
     * @return array{gateway: bool, hosted: list<string>, legacy_app: bool, requested_role_meta: string|null}|int
     */
    private function resolveRequestedRoles(): array|int
    {
        $roles = $this->roleOptions();

        if ($roles === []) {
            return ['gateway' => false, 'hosted' => [], 'legacy_app' => false, 'requested_role_meta' => null];
        }

        if ($roles === ['control']) {
            if (! $this->wantsJson()) {
                $this->warn('The legacy control role now maps to a client identity with no hosted roles.');
            }

            return ['gateway' => false, 'hosted' => [], 'legacy_app' => false, 'requested_role_meta' => 'control'];
        }

        if ($roles === ['gateway']) {
            return ['gateway' => true, 'hosted' => [], 'legacy_app' => false, 'requested_role_meta' => 'gateway'];
        }

        if ($roles === ['app']) {
            $environment = $this->stringOption('environment');

            if ($environment === null) {
                return [
                    'gateway' => false,
                    'hosted' => [],
                    'legacy_app' => true,
                    'requested_role_meta' => 'app',
                ];
            }

            if (! in_array($environment, ['development', 'production'], true)) {
                return $this->validationFailed('environment', 'Environment must be one of development or production.');
            }

            return [
                'gateway' => false,
                'hosted' => [$environment === 'development' ? NodeRoleName::AppDevelopment->value : NodeRoleName::AppProduction->value],
                'legacy_app' => true,
                'requested_role_meta' => 'app',
            ];
        }

        $canonicalRoles = [
            NodeRoleName::AppDevelopment->value,
            NodeRoleName::AppProduction->value,
            NodeRoleName::Database->value,
        ];

        foreach ($roles as $role) {
            if (! in_array($role, $canonicalRoles, true)) {
                return $this->validationFailed('role', 'Node role must be one of gateway, control, app-development, app-production, database, or legacy app.');
            }
        }

        if (in_array(NodeRoleName::AppDevelopment->value, $roles, true) && in_array(NodeRoleName::AppProduction->value, $roles, true)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Hosted roles app-development and app-production cannot be combined.',
                meta: [
                    'field' => 'role',
                    'conflicts' => [NodeRoleName::AppDevelopment->value, NodeRoleName::AppProduction->value],
                ],
            );
        }

        return [
            'gateway' => false,
            'hosted' => array_values(array_unique($roles)),
            'legacy_app' => false,
            'requested_role_meta' => $roles[0] ?? null,
        ];
    }

    private function resolveHost(string $role): ?string
    {
        $host = $this->stringOption('host');

        if ($host !== null) {
            return $host;
        }

        if (! $this->isInteractiveInput()) {
            return null;
        }

        if (! in_array($role, ['app', 'gateway'], true)) {
            return null;
        }

        return trim(text(
            label: 'Host',
            required: true,
            validate: fn (string $value): ?string => $this->validatePromptHost($value),
        ));
    }

    private function resolveControlName(string $gatewayName): ?string
    {
        $controlName = $this->stringOption('control-name');

        if ($controlName !== null) {
            return $controlName;
        }

        $default = $this->defaultControlName();

        if (! $this->isInteractiveInput()) {
            return $default;
        }

        return trim(text(
            label: 'Control node name',
            default: $default ?? '',
            required: true,
            validate: fn (string $value): ?string => $this->validatePromptControlName($value, $gatewayName),
        ));
    }

    private function resolveSshUser(): string
    {
        $sshUser = $this->stringOption('user') ?? 'root';

        if (! $this->isInteractiveInput() || $this->sshUserOptionWasSupplied()) {
            return $sshUser;
        }

        return trim(text(label: 'SSH user', default: $sshUser, required: true));
    }

    private function sshUserOptionWasSupplied(): bool
    {
        return $this->input->hasParameterOption('--user', true);
    }

    private function forbiddenControlInput(): ?string
    {
        if ($this->stringOption('host') !== null) {
            return 'host';
        }

        if ($this->stringOption('environment') !== null) {
            return 'environment';
        }

        if ($this->stringOption('tld') !== null) {
            return 'tld';
        }

        if ($this->sshUserOptionWasSupplied()) {
            return 'user';
        }

        return null;
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    private function validatePromptNodeName(string $value): ?string
    {
        $name = trim($value);

        if ($name === '') {
            return 'Node name is required.';
        }

        return $this->isValidNodeName($name) ? null : 'Node name must be a valid node name.';
    }

    private function validatePromptControlName(string $value, string $gatewayName): ?string
    {
        $controlName = trim($value);

        if ($controlName === '') {
            return 'Control node name is required.';
        }

        if (! $this->isValidNodeName($controlName)) {
            return 'Control node name must be a valid node name.';
        }

        return $controlName === $gatewayName
            ? 'Control node name must be different from gateway node name.'
            : null;
    }

    private function validatePromptHost(string $value): ?string
    {
        $host = trim($value);

        if ($host === '') {
            return 'Host is required.';
        }

        return $this->isValidHost($host) ? null : 'Host must be a valid IP address or dotted DNS name.';
    }

    /**
     * @return array{host: string, environment: string, tld: ?string, sshUser: string}|int
     */
    private function resolveAppInputs(): array|int
    {
        $environment = $this->stringOption('environment');

        if ($environment === null && $this->isInteractiveInput()) {
            $environment = select(
                label: 'App node environment',
                options: ['development', 'production'],
                required: true,
            );
        }

        if ($environment === null) {
            return $this->validationFailed('environment', 'Environment is required for app nodes.');
        }

        if (! in_array($environment, ['development', 'production'], true)) {
            return $this->validationFailed('environment', 'Environment must be one of development or production.');
        }

        $host = $this->resolveHost('app');

        if ($host === null) {
            return $this->validationFailed('host', 'Host is required for app nodes.');
        }

        if (! $this->isValidHost($host)) {
            return $this->validationFailed('host', 'Host must be a valid IP address or dotted DNS name.');
        }

        $tld = $this->stringOption('tld');

        if ($environment === 'development') {
            if ($tld === null && $this->isInteractiveInput()) {
                $tld = trim(text(
                    label: 'Development TLD',
                    required: true,
                    validate: fn (string $value): ?string => $this->isValidTld(trim($value))
                        ? null
                        : 'TLD must be a lowercase DNS label without a leading dot.',
                ));
            }

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
            'sshUser' => $this->resolveSshUser(),
        ];
    }

    /**
     * @param  list<string>  $roles
     * @return array{host: string, environment: ?string, tld: ?string, sshUser: ?string}|int
     */
    private function resolveHostedRoleInputs(array $roles): array|int
    {
        if ($this->stringOption('environment') !== null) {
            return $this->validationFailed('environment', 'Environment is only supported for legacy app role mapping.');
        }

        $needsHost = array_intersect($roles, [
            NodeRoleName::AppDevelopment->value,
            NodeRoleName::AppProduction->value,
        ]) !== [];

        if (! $needsHost && $this->stringOption('host') !== null) {
            return $this->validationFailed('host', 'Only app-development, app-production, and gateway use host provisioning.');
        }

        $host = $needsHost ? $this->resolveHost('app') : null;

        if ($needsHost && $host === null) {
            return $this->validationFailed('host', 'Host is required for hosted app roles.');
        }

        if ($host !== null && ! $this->isValidHost($host)) {
            return $this->validationFailed('host', 'Host must be a valid IP address or dotted DNS name.');
        }

        $tld = $this->stringOption('tld');

        if (in_array(NodeRoleName::AppDevelopment->value, $roles, true)) {
            if ($tld === null) {
                return $this->validationFailed('tld', 'Development app nodes require a TLD.');
            }

            if (! $this->isValidTld($tld)) {
                return $this->validationFailed('tld', 'TLD must be a lowercase DNS label without a leading dot.');
            }
        } elseif ($tld !== null) {
            return $this->validationFailed('tld', 'Only app-development uses a development TLD.');
        }

        return [
            'host' => $host ?? '',
            'environment' => $this->legacyEnvironmentForRoles($roles),
            'tld' => $tld,
            'sshUser' => $needsHost ? $this->resolveSshUser() : null,
        ];
    }

    /**
     * @param  list<string>  $roles
     */
    private function legacyEnvironmentForRoles(array $roles): ?string
    {
        if (in_array(NodeRoleName::AppDevelopment->value, $roles, true)) {
            return 'development';
        }

        if (in_array(NodeRoleName::AppProduction->value, $roles, true)) {
            return 'production';
        }

        return null;
    }

    /**
     * @param  list<string>  $roles
     */
    private function containsAppHostingRole(array $roles): bool
    {
        return array_intersect($roles, [
            NodeRoleName::AppDevelopment->value,
            NodeRoleName::AppProduction->value,
        ]) !== [];
    }

    /**
     * @param  list<string>  $roles
     */
    private function firstRole(array $roles): string
    {
        return $roles[0] ?? 'control';
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsForRole(string $role, ?string $tld): array
    {
        if ($role === NodeRoleName::AppDevelopment->value) {
            return ['tld' => $tld];
        }

        return [];
    }

    /**
     * @param  list<string>  $roles
     */
    private function ensureInitialHostedRoles(
        Node $node,
        NodeRoleAssignmentService $roleAssignmentService,
        array $roles,
        ?string $tld,
    ): ?int {
        foreach ($roles as $role) {
            $existingAssignment = $node->roleAssignments()->where('role', $role)->first();

            $assignment = $existingAssignment instanceof NodeRoleAssignment
                ? $roleAssignmentService->update($node, $role, $this->settingsForRole($role, $tld))
                : $roleAssignmentService->add($node, $role, $this->settingsForRole($role, $tld));

            if ($assignment->status !== NodeRoleStatus::Error->value) {
                continue;
            }

            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Node '{$node->name}' created but hosted role '{$assignment->role}' failed to converge.",
                meta: [
                    'node' => $node->name,
                    'role' => $assignment->role,
                    'status' => $assignment->status,
                    'settings' => $assignment->settings ?? [],
                    'last_error' => $assignment->last_error,
                ],
            );
        }

        return null;
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

    private function isValidHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (! str_contains($host, '.')) {
            return false;
        }

        if (strlen($host) > 253 || str_contains($host, '..')) {
            return false;
        }

        $labels = explode('.', trim($host, '.'));

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }

            if (! preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }

    private function gatewayEndpoint(): ?string
    {
        /** @var Node|null $gateway */
        $gateway = $this->gatewayQuery()
            ->first();

        if (! $gateway instanceof Node) {
            return null;
        }

        return $gateway->wireguard_address ?? $gateway->gateway_endpoint ?? $gateway->host;
    }

    private function gatewayQuery(): Builder
    {
        return Node::query()
            ->where('status', 'active')
            ->where(function (Builder $query): void {
                $query
                    ->where('role', 'gateway')
                    ->orWhereIn('id', app(NodeRoleAssignments::class)->activeNodeIdsForRole('gateway'));
            });
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

    private function guardDevelopmentDnsMappingAvailable(?string $tld, string $target): ?int
    {
        if ($tld === null) {
            return null;
        }

        $path = app(DevelopmentDnsMappingEnactor::class)->configDir()."/{$tld}.conf";

        if (! File::exists($path)) {
            return null;
        }

        $actualTarget = $this->developmentDnsTargetFrom(File::get($path), $tld);

        if ($actualTarget === $target) {
            return null;
        }

        return $this->failCommand(
            code: 'node.incompatible',
            message: "Development TLD '{$tld}' is already mapped to another gateway development DNS target.",
            meta: [
                'field' => 'tld',
                'value' => $tld,
                'target' => $target,
                'actual_target' => $actualTarget,
                'path' => $path,
            ],
        );
    }

    private function developmentDnsTargetFrom(string $content, string $tld): ?string
    {
        if (preg_match('/^address=\/(?:\\.)?'.preg_quote($tld, '/').'\/(.+)$/m', $content, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
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
