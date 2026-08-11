<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Contracts\RemoteShell;
use App\Data\Nodes\RoleSettings\S3RoleSettings;
use App\Enums\Nodes\NodeConvergenceContext;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeBootstrap;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Models\WireGuardPeer;
use App\Services\Analytics\AnalyticsDatabaseResolver;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Nodes\Access\NodePermissionNormalizer;
use App\Services\Nodes\Access\NodePermissionPresets;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Nodes\Roles\RoleSelfGrantMaterializer;
use App\Services\S3\S3RouteRegistrar;
use App\Services\Security\HomeDirectoryLockdownInstaller;
use App\Services\Security\PublicSshDenyInstaller;
use App\Services\Security\SecurityInstaller;
use App\Services\Security\SshdHardenedInstaller;
use App\Services\Security\SysctlBaselineInstaller;
use App\Services\Security\UnattendedUpgradesInstaller;
use App\Services\Support\GatewayActionResult;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolInstaller;
use App\Services\Tools\ToolRegistryFailure;
use App\Services\Vpn\VpnDnsSwarmInstaller;
use App\Services\Vpn\WgEasyAddressReservationProbe;
use App\Services\WireGuard\WireGuardKeyGenerator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Nodes\NodeTld;
use RuntimeException;
use Throwable;

final class GatewayNodeCreator
{
    public function __construct(
        private readonly S3RouteRegistrar $s3RouteRegistrar,
        private readonly AnalyticsDatabaseResolver $analyticsDatabaseResolver,
        private readonly DnsmasqReconciler $dnsmasqReconciler,
    ) {}

    private const string DEFAULT_RUNTIME_USER = 'orbit';

    private const int SUCCESS = 0;

    private const int WIREGUARD_RESERVATION_ATTEMPTS = 3;

    private const string WIREGUARD_RESERVATION_LOCK = 'orbit:node-bootstrap:wireguard-reservation';

    /** @var array<string, mixed> */
    private array $arguments = [];

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function create(array $arguments): GatewayActionResult
    {
        return $this->execute(
            $arguments,
            fn (
                string $name,
                array $_roles,
                WorkloadNodeProvisioningInput $inputs,
                ?int $_ingressNodeId,
            ): GatewayActionResult => $this->clientBootstrapRequired($name, $inputs->host),
            requireObservedPlatform: false,
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function prepareBootstrap(array $arguments, Node $caller): GatewayActionResult
    {
        $arguments['--json'] = true;
        $request = array_diff_key($arguments, ['--json' => true]);

        return $this->execute(
            $arguments,
            fn (
                string $name,
                array $roles,
                WorkloadNodeProvisioningInput $inputs,
                ?int $_ingressNodeId,
            ): GatewayActionResult => $this->prepareHostBootstrap(
                registryWriter: app(NodeRegistryWriter::class),
                wireGuardKeyGenerator: app(WireGuardKeyGenerator::class),
                name: $name,
                roles: $roles,
                inputs: $inputs,
                caller: $caller,
                request: $request,
            ),
            requireObservedPlatform: true,
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function resumeBootstrap(array $arguments, Node $caller): GatewayActionResult
    {
        /** @var mixed $rawName */
        $rawName = $arguments['name'] ?? null;
        $name = is_string($rawName) ? trim($rawName) : '';
        $node = $name !== '' ? Node::query()->where('name', $name)->first() : null;

        if (! $node instanceof Node) {
            /** @var array<string, mixed> $payload */
            $payload = JsonEnvelope::success(['preflight_required' => true]);

            return new GatewayActionResult(
                exitCode: self::SUCCESS,
                payload: $payload,
            );
        }

        $bootstrap = NodeBootstrap::query()->where('node_id', $node->id)->first();
        $request = array_diff_key($arguments, [
            '--json' => true,
            '--platform' => true,
            '--architecture' => true,
        ]);
        $storedRequest = $bootstrap instanceof NodeBootstrap
            ? array_diff_key($bootstrap->request, [
                '--platform' => true,
                '--architecture' => true,
            ])
            : [];

        if (
            ! $bootstrap instanceof NodeBootstrap
            || $bootstrap->initiating_node_id !== $caller->id
            || $storedRequest !== $request
        ) {
            return GatewayActionResult::error(
                code: 'node.incompatible',
                message: "Node '{$name}' already exists with incompatible bootstrap state.",
                meta: ['name' => $name],
            );
        }

        if ($bootstrap->status === 'completed' && $node->isActive()) {
            return $this->resumedBootstrapResult($bootstrap, 'completed');
        }

        if ($bootstrap->status !== 'pending' || ! $node->isProvisioning()) {
            return GatewayActionResult::error(
                code: 'node.incompatible',
                message: 'Node bootstrap is not in a compatible resumable state.',
                meta: ['bootstrap_id' => $bootstrap->id],
            );
        }

        if (app(ProvisioningAgentReadinessProbe::class)->isReady($node)) {
            return $this->resumedBootstrapResult($bootstrap, 'pending');
        }

        /** @var array<string, mixed> $payload */
        $payload = JsonEnvelope::success([
            'preflight_required' => true,
            'bootstrap' => [
                'id' => $bootstrap->id,
                'status' => 'pending',
                'ssh_required' => true,
            ],
        ]);

        return new GatewayActionResult(
            exitCode: self::SUCCESS,
            payload: $payload,
        );
    }

    public function completeBootstrap(NodeBootstrap $bootstrap, Node $caller): NodeBootstrapCompletionResult
    {
        try {
            return app(NodeBootstrapCompletionLock::class)->synchronized(
                $bootstrap->id,
                fn (): NodeBootstrapCompletionResult => $this->completeBootstrapWhileLocked($bootstrap, $caller),
            );
        } catch (LockTimeoutException) {
            return new NodeBootstrapCompletionResult(
                result: GatewayActionResult::error(
                    code: 'node.provisioning_incomplete',
                    message: 'Node bootstrap completion is already in progress; retry shortly.',
                    meta: [
                        'bootstrap_id' => $bootstrap->id,
                        'step' => 'completion_lock',
                    ],
                ),
                completedNow: false,
            );
        }
    }

    private function completeBootstrapWhileLocked(
        NodeBootstrap $bootstrap,
        Node $caller,
    ): NodeBootstrapCompletionResult {
        $bootstrap->refresh();
        $node = Node::query()->find($bootstrap->node_id);

        if ($bootstrap->initiating_node_id !== $caller->id) {
            return new NodeBootstrapCompletionResult(
                result: GatewayActionResult::error(
                    code: 'authorization_failed',
                    message: 'Only the initiating node can complete this bootstrap.',
                    meta: ['bootstrap_id' => $bootstrap->id],
                ),
                completedNow: false,
            );
        }

        if ($bootstrap->status === 'completed' && $node instanceof Node && $node->isActive()) {
            $this->syncActiveS3ServiceRoute($node);
            $this->dnsmasqReconciler->reconcileRecords();

            return new NodeBootstrapCompletionResult(
                result: $this->completedBootstrapResult($bootstrap, $node),
                completedNow: false,
            );
        }

        if ($bootstrap->status !== 'pending' || ! $node instanceof Node || ! $node->isProvisioning()) {
            return new NodeBootstrapCompletionResult(
                result: GatewayActionResult::error(
                    code: 'node.incompatible',
                    message: 'Node bootstrap is not in a compatible pending state.',
                    meta: ['bootstrap_id' => $bootstrap->id],
                ),
                completedNow: false,
            );
        }

        try {
            $result = $this->execute(
                [...$bootstrap->request, '--json' => true],
                fn (
                    string $name,
                    array $roles,
                    WorkloadNodeProvisioningInput $inputs,
                    ?int $ingressNodeId,
                ): GatewayActionResult => $this->completePreparedWorkloadNode(
                    roleAssignmentService: app(NodeRoleAssignmentService::class),
                    nodeConverger: app(NodeConverger::class),
                    name: $name,
                    roles: $roles,
                    inputs: $inputs,
                    appProductionIngressNodeId: $ingressNodeId,
                    bootstrap: $bootstrap,
                ),
                requireObservedPlatform: false,
            );
        } catch (Throwable $exception) {
            $completed = $this->refreshCompletedBootstrap($bootstrap, $node);

            if ($completed instanceof GatewayActionResult) {
                return new NodeBootstrapCompletionResult(
                    result: $completed,
                    completedNow: false,
                );
            }

            if ($bootstrap->status === 'pending') {
                $bootstrap->forceFill([
                    'last_error' => [
                        'code' => 'node.provisioning_incomplete',
                        'message' => $exception->getMessage(),
                    ],
                ])->save();
            }

            return new NodeBootstrapCompletionResult(
                result: GatewayActionResult::error(
                    code: 'node.provisioning_incomplete',
                    message: 'Node bootstrap completion failed.',
                    meta: [
                        'bootstrap_id' => $bootstrap->id,
                        'error' => $exception->getMessage(),
                    ],
                ),
                completedNow: false,
            );
        }

        if ($result->successful()) {
            try {
                $completedNow = DB::transaction(static function () use ($bootstrap, $node): bool {
                    $transitioned = NodeBootstrap::query()
                        ->whereKey($bootstrap->id)
                        ->where('status', 'pending')
                        ->update([
                            'status' => 'completed',
                            'last_error' => null,
                        ]);

                    if ($transitioned !== 1) {
                        return false;
                    }

                    $node->forceFill(['status' => NodeStatus::Active])->save();

                    return true;
                });
            } catch (Throwable $exception) {
                $completed = $this->refreshCompletedBootstrap($bootstrap, $node);

                if ($completed instanceof GatewayActionResult) {
                    return new NodeBootstrapCompletionResult(
                        result: $completed,
                        completedNow: false,
                    );
                }

                if ($bootstrap->status === 'pending') {
                    $bootstrap->forceFill([
                        'last_error' => [
                            'code' => 'node.provisioning_incomplete',
                            'message' => $exception->getMessage(),
                        ],
                    ])->save();
                }

                return new NodeBootstrapCompletionResult(
                    result: GatewayActionResult::error(
                        code: 'node.provisioning_incomplete',
                        message: 'Node bootstrap terminal state could not be committed.',
                        meta: [
                            'bootstrap_id' => $bootstrap->id,
                            'error' => $exception->getMessage(),
                        ],
                    ),
                    completedNow: false,
                );
            }

            if (! $completedNow) {
                $completed = $this->refreshCompletedBootstrap($bootstrap, $node);

                if ($completed instanceof GatewayActionResult) {
                    return new NodeBootstrapCompletionResult(
                        result: $completed,
                        completedNow: false,
                    );
                }

                return new NodeBootstrapCompletionResult(
                    result: GatewayActionResult::error(
                        code: 'node.incompatible',
                        message: 'Node bootstrap completion lost its pending transition.',
                        meta: ['bootstrap_id' => $bootstrap->id],
                    ),
                    completedNow: false,
                );
            }

            $this->syncActiveS3ServiceRoute($node);
            $this->dnsmasqReconciler->reconcileRecords();

            return new NodeBootstrapCompletionResult(
                result: $result,
                completedNow: true,
            );
        }

        $completed = $this->refreshCompletedBootstrap($bootstrap, $node);

        if ($completed instanceof GatewayActionResult) {
            return new NodeBootstrapCompletionResult(
                result: $completed,
                completedNow: false,
            );
        }

        if ($bootstrap->status === 'pending') {
            $bootstrap->forceFill([
                'last_error' => is_array($result->payload['error'] ?? null) ? $result->payload['error'] : null,
            ])->save();
        }

        return new NodeBootstrapCompletionResult(
            result: $result,
            completedNow: false,
        );
    }

    private function syncActiveS3ServiceRoute(Node $node): void
    {
        $hasActiveS3Role = $node
            ->roleAssignments()
            ->where('role', NodeRoleName::S3->value)
            ->where('status', NodeRoleStatus::Active->value)
            ->exists();

        if (! $hasActiveS3Role) {
            return;
        }

        $this->s3RouteRegistrar->syncServiceRoute();
    }

    private function refreshCompletedBootstrap(
        NodeBootstrap $bootstrap,
        Node $node,
    ): ?GatewayActionResult {
        $bootstrap->refresh();
        $node->refresh();

        if ($bootstrap->status === 'completed' && $node->isActive()) {
            return $this->completedBootstrapResult($bootstrap, $node);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  callable(string, list<string>, WorkloadNodeProvisioningInput, ?int): GatewayActionResult  $provisionWorkload
     */
    private function execute(
        array $arguments,
        callable $provisionWorkload,
        bool $requireObservedPlatform,
    ): GatewayActionResult {
        $this->arguments = $arguments;

        return $this->handle(
            app(NodeRoleAssignmentService::class),
            app(WireGuardKeyGenerator::class),
            $provisionWorkload,
            $requireObservedPlatform,
        );
    }

    /**
     * @param  callable(string, list<string>, WorkloadNodeProvisioningInput, ?int): GatewayActionResult  $provisionWorkload
     */
    private function handle(
        NodeRoleAssignmentService $nodeRoleAssignmentService,
        WireGuardKeyGenerator $wireGuardKeyGenerator,
        callable $provisionWorkload,
        bool $requireObservedPlatform,
    ): GatewayActionResult {
        $name = $this->resolveName();

        try {
            $requestedRoles = app(NodeCreationRoleResolver::class)->resolve(
                template: $this->stringOption('template'),
                operator: (bool) $this->option('operator'),
                roles: $this->stringOption('roles'),
            );
        } catch (NodeCreationRoleInputException $exception) {
            return $this->failCommand(
                code: $exception->errorCode,
                message: $exception->getMessage(),
                meta: $exception->meta,
            );
        }

        if ($name === null) {
            return $this->validationFailed('name', 'Node name is required.');
        }

        if (! $this->isValidNodeName($name)) {
            return $this->validationFailed('name', 'Node name must be a valid node name.');
        }

        if (
            $this->arrayOption('agent-tool') !== []
            && ! in_array(NodeRoleName::Agent->value, $requestedRoles->workloadRoles, true)
        ) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Agent tools can only be specified for agent nodes.',
                meta: ['field' => 'agent-tool'],
            );
        }

        $gatewayConfigured = $this->gatewayConfigured();

        if ($requestedRoles->workloadRoles !== []) {
            $inputs = $this->resolveWorkloadRoleInputs(
                $requestedRoles->workloadRoles,
                $requireObservedPlatform,
            );

            if ($inputs instanceof GatewayActionResult) {
                return $inputs;
            }

            $placement = $this->resolveIngressPlacement(
                $requestedRoles->workloadRoles,
                validateLocalIngressRegistry: true,
            );

            if ($placement instanceof GatewayActionResult) {
                return $placement;
            }

            if (! $gatewayConfigured) {
                return $this->failCommand(
                    code: 'gateway_unavailable',
                    message: 'Gateway connection is required before creating workload nodes.',
                    meta: ['requested_role' => $requestedRoles->requestedRoleMeta],
                );
            }

            if ($this->containsAppWorkloadRole($placement->roles)) {
                return $provisionWorkload(
                    $name,
                    $placement->roles,
                    $inputs,
                    $placement->ingressNodeId,
                );
            }

            return $this->provisionWorkloadRoleNode(
                roleAssignmentService: $nodeRoleAssignmentService,
                name: $name,
                roles: $placement->roles,
                inputs: $inputs,
                appProductionIngressNodeId: $placement->ingressNodeId,
                provisionWorkload: $provisionWorkload,
            );
        }

        if ($requestedRoles->clientIdentity || $requestedRoles->operator) {
            $forbiddenInput = $this->forbiddenClientIdentityInput();

            if ($forbiddenInput !== null) {
                return $this->validationFailed(
                    $forbiddenInput,
                    'Client identities do not use workload or SSH/bootstrap-only input.',
                );
            }

            return $this->enrollClientNode($wireGuardKeyGenerator, $name, $requestedRoles->operator);
        }

        if ($requestedRoles->gateway) {
            return $this->convergeGatewayLocally($name);
        }

        return $this->failCommand(
            code: 'gateway_unavailable',
            message: 'Gateway connection is required before creating nodes.',
            meta: ['requested_role' => $requestedRoles->requestedRoleMeta],
        );
    }

    /**
     * @param  list<string>  $roles
     * @param  callable(string, list<string>, WorkloadNodeProvisioningInput, ?int): GatewayActionResult  $provisionWorkload
     * @mago-expect lint:halstead
     */
    private function provisionWorkloadRoleNode(
        NodeRoleAssignmentService $roleAssignmentService,
        string $name,
        array $roles,
        WorkloadNodeProvisioningInput $inputs,
        ?int $appProductionIngressNodeId,
        callable $provisionWorkload,
    ): GatewayActionResult {
        $existing = Node::query()->where('name', $name)->first();

        if ($existing instanceof Node && $existing->isActive()) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Node '{$name}' already exists.",
                meta: ['name' => $name],
            );
        }

        try {
            foreach ($roles as $role) {
                $roleAssignmentService->assertFleetRoleAvailable($role, $existing);
            }
        } catch (InvalidArgumentException $exception) {
            return $this->failCommand(
                code: 'validation_failed',
                message: $exception->getMessage(),
                meta: [
                    'field' => 'roles',
                    'role' => NodeRoleName::Analytics->value,
                ],
            );
        }

        if (
            $inputs->tld !== null
            && Node::query()->where('tld', $inputs->tld)->where('status', NodeStatus::Active->value)->exists()
        ) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Node TLD '{$inputs->tld}' is already assigned to another node.",
                meta: [
                    'field' => 'tld',
                    'value' => $inputs->tld,
                ],
            );
        }

        $preflight = $this->preflightAgentSetup($roles);
        if ($preflight instanceof GatewayActionResult) {
            return $preflight;
        }

        return $provisionWorkload($name, $roles, $inputs, $appProductionIngressNodeId);
    }

    private function ensureProvisionedNodeWireGuardPeer(
        Node $node,
        WireGuardKeyGenerator $wireGuardKeyGenerator,
        string $wireguardAddress,
    ): WireGuardPeer|GatewayActionResult {
        $peer = WireGuardPeer::query()->where('node_id', $node->id)->first();

        if ($peer instanceof WireGuardPeer && $peer->private_key !== '') {
            if (! is_string($peer->pre_shared_key) || $peer->pre_shared_key === '') {
                $peer->pre_shared_key = $this->generatePreSharedKey();
            }

            $peer->allowed_ips = "{$wireguardAddress}/32";
            $peer->save();

            return $peer;
        }

        try {
            $keys = $wireGuardKeyGenerator->generateKeyPair();
            $preSharedKey = $this->generatePreSharedKey();
        } catch (RuntimeException $exception) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Failed to generate WireGuard identity material.',
                meta: [
                    'node' => $node->name,
                    'step' => 'wireguard_identity',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        return WireGuardPeer::query()->updateOrCreate(
            ['node_id' => $node->id],
            [
                'public_key' => $keys['public_key'],
                'private_key' => $keys['private_key'],
                'pre_shared_key' => $preSharedKey,
                'allowed_ips' => "{$wireguardAddress}/32",
            ],
        );
    }

    private function configureGatewayWireGuardServerPeer(
        Node $node,
        WireGuardPeer $peer,
        string $wireguardAddress,
    ): string|GatewayActionResult {
        if ($peer->public_key === '' || $peer->pre_shared_key === null || $peer->pre_shared_key === '') {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Node '{$node->name}' created but WireGuard peer material is incomplete.",
                meta: [
                    'node' => $node->name,
                    'step' => 'wireguard_identity',
                ],
            );
        }

        try {
            $installer = app(VpnDnsSwarmInstaller::class);
            $installer->configurePeers([
                [
                    'name' => $node->name,
                    'private_key' => $peer->private_key,
                    'public_key' => $peer->public_key,
                    'pre_shared_key' => $peer->pre_shared_key,
                    'address' => $wireguardAddress,
                ],
            ]);

            return $installer->publicKey();
        } catch (RuntimeException $exception) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Gateway could not install WireGuard peer for node '{$node->name}'.",
                meta: [
                    'node' => $node->name,
                    'step' => 'gateway_wireguard_peer',
                    'error' => $exception->getMessage(),
                ],
            );
        }
    }

    private function gatewayPublicEndpoint(Node $gateway): ?string
    {
        $vpnRole = $gateway
            ->roleAssignments()
            ->where('role', NodeRoleName::Vpn->value)
            ->first();

        $settings = $vpnRole?->settings;
        $publicEndpoint = is_array($settings) ? $settings['public_endpoint'] ?? null : null;

        if (is_string($publicEndpoint) && $publicEndpoint !== '') {
            return $publicEndpoint;
        }

        foreach ([$gateway->gateway_endpoint, $gateway->host] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function convergeGatewayLocally(string $name): GatewayActionResult
    {
        $tld = $this->stringOption('tld');

        if ($tld === null || ! NodeTld::isValid($tld)) {
            return $this->validationFailed('tld', 'Every node requires an explicit valid non-reserved TLD.');
        }

        $host = $this->resolveHost();

        if ($host === null) {
            return $this->validationFailed('host', 'Host is required for gateway nodes.');
        }

        if (! $this->isValidHost($host)) {
            return $this->validationFailed('host', 'Host must be a valid IP address or dotted DNS name.');
        }

        $gateway = $this
            ->gatewayQuery()
            ->where('name', $name)
            ->first();

        if (
            ! $gateway instanceof Node
            || ! $this->gatewayHostMatches($gateway, $host)
            || $gateway->tld !== $tld
        ) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: 'Existing gateway is incompatible with the requested host or identity.',
                meta: ['name' => $name, 'host' => $host],
            );
        }

        $payload = $this->gatewayConvergencePayload($gateway, $host);

        return $this->jsonSuccess($payload);
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
                'tld' => $gateway->tld,
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

    private function enrollClientNode(
        WireGuardKeyGenerator $wireGuardKeyGenerator,
        string $name,
        bool $operator,
    ): GatewayActionResult {
        $existing = Node::query()->where('name', $name)->first();

        if ($existing instanceof Node && ! $existing->isOperator()) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Node '{$name}' already exists with a different role.",
                meta: [
                    'name' => $name,
                    'existing_role' => $existing->displayRole(),
                    'requested_role' => $operator ? 'operator' : 'client',
                ],
            );
        }

        $wireguardAddress =
            $existing instanceof Node && is_string($existing->wireguard_address) && $existing->wireguard_address !== ''
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

        $tld = $this->stringOption('tld');

        if ($tld === null || ! NodeTld::isValid($tld)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Every node identity requires an explicit valid non-reserved TLD.',
                meta: [
                    'field' => 'tld',
                    'name' => $name,
                ],
            );
        }

        if ($existing instanceof Node && $existing->tld !== $tld) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Node '{$name}' already exists with a different TLD.",
                meta: ['field' => 'tld', 'existing' => $existing->tld, 'requested' => $tld],
            );
        }

        $tldConflict = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->where('tld', $tld);

        if ($existing instanceof Node) {
            $tldConflict->whereKeyNot($existing->id);
        }

        if ($tldConflict->exists()) {
            return $this->failCommand(
                code: 'node.tld_in_use',
                message: "Node TLD '{$tld}' is already assigned to another node.",
                meta: ['field' => 'tld', 'value' => $tld],
            );
        }

        $node = Node::query()->updateOrCreate(
            ['name' => $name],
            [
                'tld' => $tld,
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

        $this->dnsmasqReconciler->reconcileRecords();

        $clientLabel = $operator ? 'operator node' : 'client';

        $payload = [
            'result' => [
                'action' => 'enrolled',
            ],
            'node' => [
                'name' => $name,
                'tld' => $tld,
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
                "Install the WireGuard configuration on the {$clientLabel}.",
                'Join the Orbit WireGuard network.',
                "Run `orbit gateway:add` on the {$clientLabel}.",
            ],
        ];

        return $this->jsonSuccess($payload);
    }

    /**
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $request
     */
    private function prepareHostBootstrap(
        NodeRegistryWriter $registryWriter,
        WireGuardKeyGenerator $wireGuardKeyGenerator,
        string $name,
        array $roles,
        WorkloadNodeProvisioningInput $inputs,
        Node $caller,
        array $request,
    ): GatewayActionResult {
        for ($attempt = 1; $attempt <= self::WIREGUARD_RESERVATION_ATTEMPTS; $attempt++) {
            try {
                /** @var GatewayActionResult */
                return Cache::lock(self::WIREGUARD_RESERVATION_LOCK, 120)->block(
                    30,
                    fn (): GatewayActionResult => $this->prepareHostBootstrapWithReservationLock(
                        $registryWriter,
                        $wireGuardKeyGenerator,
                        $name,
                        $roles,
                        $inputs,
                        $caller,
                        $request,
                    ),
                );
            } catch (QueryException $exception) {
                if (
                    $attempt === self::WIREGUARD_RESERVATION_ATTEMPTS
                    || ! $this->isWireguardAddressCollision($exception)
                ) {
                    throw $exception;
                }
            } catch (LockTimeoutException) {
                return $this->failCommand(
                    code: 'node.provisioning_incomplete',
                    message: 'WireGuard identity reservation is busy; retry node bootstrap.',
                    meta: [
                        'node' => $name,
                        'step' => 'wireguard_allocation',
                    ],
                );
            }
        }

        throw new RuntimeException('WireGuard identity reservation attempts were exhausted.');
    }

    /**
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $request
     */
    private function prepareHostBootstrapWithReservationLock(
        NodeRegistryWriter $registryWriter,
        WireGuardKeyGenerator $wireGuardKeyGenerator,
        string $name,
        array $roles,
        WorkloadNodeProvisioningInput $inputs,
        Node $caller,
        array $request,
    ): GatewayActionResult {
        $preflight = $this->preflightAgentSetup($roles);

        if ($preflight instanceof GatewayActionResult) {
            return $preflight;
        }

        $existing = Node::query()->where('name', $name)->first();
        $bootstrap = $existing instanceof Node
            ? NodeBootstrap::query()->where('node_id', $existing->id)->first()
            : null;

        if (
            $existing instanceof Node
            && ! $this->pendingBootstrapIsCompatible(
                node: $existing,
                bootstrap: $bootstrap,
                caller: $caller,
                request: $request,
                inputs: $inputs,
            )
        ) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Node '{$name}' already exists with incompatible bootstrap state.",
                meta: ['name' => $name],
            );
        }

        $tld = $inputs->tld;

        if (
            is_string($tld)
            && Node::query()->where('tld', $tld)->where('name', '!=', $name)->exists()
        ) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "Node TLD '{$tld}' is already assigned to another node.",
                meta: [
                    'field' => 'tld',
                    'value' => $tld,
                ],
            );
        }

        $wireguardAddress = $existing?->wireguard_address;

        if (! is_string($wireguardAddress) || trim($wireguardAddress) === '') {
            $wireguardAddress = $this->resolveProvisionedNodeWireguardAddress();
        }

        if ($wireguardAddress instanceof GatewayActionResult) {
            return $wireguardAddress;
        }

        $gateway = $this->gatewayQuery()->first();

        if (! $gateway instanceof Node) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Gateway identity is missing locally.',
                meta: [
                    'node' => $name,
                    'step' => 'gateway_identity',
                ],
            );
        }

        $gatewayEndpoint = $inputs->gatewayEndpoint ?? $this->gatewayPublicEndpoint($gateway);

        if ($gatewayEndpoint === null) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: 'Gateway public WireGuard endpoint is missing locally.',
                meta: [
                    'node' => $gateway->name,
                    'step' => 'gateway_wireguard_endpoint',
                ],
            );
        }

        DB::beginTransaction();

        try {
            $node = $existing ?? $registryWriter->writeNodeIdentity(
                name: $name,
                tld: $tld,
                platform: $inputs->platform,
                host: $inputs->host,
                wireguardAddress: $wireguardAddress,
                gatewayEndpoint: $gatewayEndpoint,
                user: self::DEFAULT_RUNTIME_USER,
                orbitPath: '/home/'.self::DEFAULT_RUNTIME_USER.'/orbit',
                status: NodeStatus::Provisioning,
                architecture: $inputs->architecture,
            );
            $node->forceFill([
                'platform' => $inputs->platform,
                'architecture' => $inputs->architecture,
                'managed' => true,
                'status' => NodeStatus::Provisioning,
            ])->save();

            $peer = $this->ensureProvisionedNodeWireGuardPeer($node, $wireGuardKeyGenerator, $wireguardAddress);

            if ($peer instanceof GatewayActionResult) {
                DB::rollBack();

                return $peer;
            }

            $bootstrap ??= NodeBootstrap::query()->create([
                'node_id' => $node->id,
                'initiating_node_id' => $caller->id,
                'request' => $request,
                'status' => 'pending',
                'last_error' => null,
            ]);

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $wireguardServerPublicKey = $this->configureGatewayWireGuardServerPeer($node, $peer, $wireguardAddress);

        if ($wireguardServerPublicKey instanceof GatewayActionResult) {
            return $wireguardServerPublicKey;
        }

        $wireguardConfig = $this->controlWireGuardConfig(
            controlPrivateKey: $peer->private_key,
            controlWireguardAddress: $wireguardAddress,
            gatewayPublicKey: $wireguardServerPublicKey,
            gatewayWireguardAddress: (string) $gateway->wireguard_address,
            gatewayEndpoint: $gatewayEndpoint,
            preSharedKey: $peer->pre_shared_key,
            allowedIps: '10.6.0.0/24',
        );

        try {
            $script = app(NodeBootstrapBundleBuilder::class)->build(
                node: $node,
                gateway: $gateway,
                peer: $peer,
                wireguardConfig: $wireguardConfig,
            );
        } catch (Throwable $exception) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Node '{$name}' bootstrap bundle could not be rendered.",
                meta: [
                    'node' => $name,
                    'step' => 'bootstrap_bundle',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        $bootstrap->forceFill([
            'status' => 'pending',
            'last_error' => null,
        ])->save();

        return $this->jsonSuccess([
            'bootstrap' => [
                'id' => $bootstrap->id,
                'status' => 'pending',
                'host' => $inputs->host,
                'user' => $inputs->sshUser ?? 'root',
                'wireguard_address' => $wireguardAddress,
                'script' => $script,
            ],
        ]);
    }

    private function isWireguardAddressCollision(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return (
            str_contains($message, 'wireguard_address')
            && (str_contains($message, 'unique') || str_contains($message, 'duplicate'))
        );
    }

    /**
     * @param  list<string>  $roles
     * @mago-expect lint:halstead
     */
    private function completePreparedWorkloadNode(
        NodeRoleAssignmentService $roleAssignmentService,
        NodeConverger $nodeConverger,
        string $name,
        array $roles,
        WorkloadNodeProvisioningInput $inputs,
        ?int $appProductionIngressNodeId,
        NodeBootstrap $bootstrap,
    ): GatewayActionResult {
        $node = Node::query()->find($bootstrap->node_id);

        if (! $node instanceof Node || $node->name !== $name) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: 'Pending bootstrap identity does not match the requested node.',
                meta: ['name' => $name],
            );
        }

        try {
            app(ProvisioningAgentReadinessProbe::class)->waitUntilReady($node);
        } catch (RuntimeException $exception) {
            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Node '{$name}' Agent is not ready through WireGuard.",
                meta: [
                    'node' => $name,
                    'step' => 'agent_readiness',
                    'error' => $exception->getMessage(),
                ],
            );
        }

        $roleAssignmentFailure = $this->ensureInitialWorkloadRoles(
            node: $node,
            roleAssignmentService: $roleAssignmentService,
            roles: $roles,
            appProductionIngressNodeId: $appProductionIngressNodeId,
            backingNodeIds: [
                'postgres' => $inputs->postgresNodeId,
                'postgres_process' => $inputs->postgresProcessId,
                'clickhouse' => $inputs->clickhouseNodeId,
            ],
        );

        if ($roleAssignmentFailure instanceof GatewayActionResult) {
            return $roleAssignmentFailure;
        }

        $warnings = [];

        if (in_array(NodeRoleName::Agent->value, $roles, true)) {
            $selfGrantFailure = $this->setupAgentSelfGrant($node);

            if ($selfGrantFailure instanceof GatewayActionResult) {
                return $selfGrantFailure;
            }

            $grantToFailure = $this->setupGrantTo($node);

            if ($grantToFailure instanceof GatewayActionResult) {
                return $grantToFailure;
            }

            $grantFromFailure = $this->setupGrantFrom($node);

            if ($grantFromFailure instanceof GatewayActionResult) {
                return $grantFromFailure;
            }

            $agentToolFailure = $this->setupAgentTools($node, $warnings);

            if ($agentToolFailure instanceof GatewayActionResult) {
                return $agentToolFailure;
            }
        }

        $nodeSetup = $this->setupManagedNode($nodeConverger, $node, $roles);

        if ($nodeSetup instanceof GatewayActionResult) {
            return $nodeSetup;
        }

        $securityBaseline = $this->finalizeNodeSecurityBaseline($node);

        if ($securityBaseline instanceof GatewayActionResult) {
            return $securityBaseline;
        }

        $payload = $this->completedNodePayload(
            node: $node,
            host: $inputs->host,
            roles: $roles,
        );

        return $this->jsonSuccess(
            $payload,
            $warnings !== [] ? ['warnings' => $warnings] : [],
        );
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, mixed>
     */
    private function completedNodePayload(
        Node $node,
        string $host,
        array $roles,
    ): array {
        $payload = [
            'result' => [
                'action' => 'created',
            ],
            'node' => [
                'name' => $node->name,
                'tld' => $node->tld,
                'platform' => $node->platform ?? 'unknown',
                'addresses' => [
                    'wireguard' => $node->wireguard_address,
                ],
                'status' => 'active',
            ],
            'roles' => $node
                ->roleAssignments()
                ->get()
                ->map(fn (NodeRoleAssignment $assignment): array => [
                    'role' => $assignment->role,
                    'status' => $assignment->status->value,
                    'settings' => $assignment->settings ?? [],
                    'last_error' => $assignment->last_error,
                ])
                ->values()
                ->all(),
            'provisioning' => [
                'transport' => 'client-ssh',
                'host' => $host,
                'status' => 'complete',
            ],
            'next_steps' => [],
        ];

        if ($this->containsDevelopmentAppRole($roles)) {
            $payload['development_tld'] = [
                'tld' => $node->tld,
                'gateway_dns' => [
                    'domain' => "*.{$node->tld}",
                    'target' => $node->wireguard_address,
                    'status' => 'configured',
                ],
            ];
        }

        return $payload;
    }

    private function completedBootstrapResult(NodeBootstrap $bootstrap, Node $node): GatewayActionResult
    {
        $roles = array_values(array_filter(
            $node->roleAssignments()->pluck('role')->all(),
            is_string(...),
        ));
        /** @var mixed $requestHost */
        $requestHost = $bootstrap->request['--host'] ?? $node->host;
        $host = is_string($requestHost) && $requestHost !== '' ? $requestHost : $node->host;
        /** @var array<string, mixed> $payload */
        $payload = JsonEnvelope::success($this->completedNodePayload($node, $host, $roles));

        return new GatewayActionResult(
            exitCode: self::SUCCESS,
            payload: $payload,
        );
    }

    private function resumedBootstrapResult(NodeBootstrap $bootstrap, string $status): GatewayActionResult
    {
        /** @var array<string, mixed> $payload */
        $payload = JsonEnvelope::success([
            'preflight_required' => false,
            'bootstrap' => [
                'id' => $bootstrap->id,
                'status' => $status,
                'ssh_required' => false,
            ],
        ]);

        return new GatewayActionResult(
            exitCode: self::SUCCESS,
            payload: $payload,
        );
    }

    private function clientBootstrapRequired(string $name, string $host): GatewayActionResult
    {
        return $this->failCommand(
            code: 'node.bootstrap_required',
            message: "Node '{$name}' must be bootstrapped over SSH by the initiating client.",
            meta: [
                'node' => $name,
                'host' => $host,
                'prepare_endpoint' => '/api/nodes/bootstrap',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function pendingBootstrapIsCompatible(
        Node $node,
        ?NodeBootstrap $bootstrap,
        Node $caller,
        array $request,
        WorkloadNodeProvisioningInput $inputs,
    ): bool {
        return (
            $node->isProvisioning()
            && $node->host === $inputs->host
            && $node->tld === $inputs->tld
            && $node->platform === $inputs->platform
            && $node->architecture === $inputs->architecture
            && $bootstrap instanceof NodeBootstrap
            && $bootstrap->initiating_node_id === $caller->id
            && $bootstrap->request === $request
            && $bootstrap->status === 'pending'
        );
    }

    /**
     * @param  list<string>  $roles
     */
    private function containsDevelopmentAppRole(array $roles): bool
    {
        return in_array(NodeRoleName::AppDevelopment->value, $roles, true);
    }

    private function finalizeNodeSecurityBaseline(Node $node): ?GatewayActionResult
    {
        $shell = app(RemoteShell::class);

        /** @var array<string, SecurityInstaller> $installers */
        $installers = [
            'home' => app(HomeDirectoryLockdownInstaller::class),
            'sshd' => app(SshdHardenedInstaller::class),
            'sysctl' => app(SysctlBaselineInstaller::class),
            'unattended_upgrades' => app(UnattendedUpgradesInstaller::class),
            'public_ssh_deny' => app(PublicSshDenyInstaller::class),
        ];

        foreach ($installers as $step => $installer) {
            $report = $installer->installFor($node, $shell);

            if ($report->successful) {
                continue;
            }

            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Host '{$node->host}' could not finalize the node security baseline.",
                meta: [
                    'host' => $node->host,
                    'step' => $step,
                    'exit_code' => $report->details['exit_code'] ?? null,
                ],
            );
        }

        return null;
    }

    /** @mago-expect lint:excessive-parameter-list */
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

    /**
     * @return list<string>
     */
    private function arrayOption(string $name): array
    {
        $value = $this->option($name);

        if (is_string($value) && $value !== '') {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn ($item): bool => is_string($item) && $item !== ''));
    }

    private function resolveName(): ?string
    {
        return $this->stringArgument('name');
    }

    private function resolveHost(): ?string
    {
        return $this->stringOption('host');
    }

    private function resolveSshUser(): string
    {
        return $this->stringOption('user') ?? 'root';
    }

    private function sshUserOptionWasSupplied(): bool
    {
        return array_key_exists('--user', $this->arguments);
    }

    private function forbiddenClientIdentityInput(): ?string
    {
        foreach ([
            'host',
            'operator-name',
            'operator-tld',
            'ingress',
            'valkey-node',
            'postgres-node',
            'postgres-process',
            'clickhouse-node',
            's3-data-path',
            'gateway-endpoint',
            'host-key-fingerprint',
        ] as $option) {
            if ($this->stringOption($option) !== null) {
                return $option;
            }
        }

        foreach (['agent-tool', 'grant-to', 'grant-from'] as $option) {
            if ($this->arrayOption($option) !== []) {
                return $option;
            }
        }

        foreach ([
            'self-grant',
            'self-grant-permissions',
            'grant-to-preset',
            'grant-to-permissions',
            'grant-from-preset',
            'grant-from-permissions',
        ] as $option) {
            if ($this->stringOption($option) !== null) {
                return $option;
            }
        }

        if ($this->sshUserOptionWasSupplied()) {
            return 'user';
        }

        return null;
    }

    /**
     * @param  list<string>  $roles
     * @return WorkloadNodeProvisioningInput|GatewayActionResult
     */
    private function resolveWorkloadRoleInputs(
        array $roles,
        bool $requireObservedPlatform,
    ): WorkloadNodeProvisioningInput|GatewayActionResult {
        $needsHost = array_intersect($roles, [
            NodeRoleName::AppDevelopment->value,
            NodeRoleName::AppProduction->value,
            NodeRoleName::Database->value,
            NodeRoleName::Ingress->value,
            NodeRoleName::Agent->value,
            NodeRoleName::Metrics->value,
            NodeRoleName::Analytics->value,
            NodeRoleName::S3->value,
        ]) !== [];

        if (! $needsHost && $this->stringOption('host') !== null) {
            return $this->validationFailed(
                'host',
                'Only app-dev, app-prod, database, ingress, agent, s3, metrics, analytics, and gateway use host provisioning.',
            );
        }

        if (! $needsHost && $this->stringOption('host-key-fingerprint') !== null) {
            return $this->validationFailed(
                'host_key_fingerprint',
                'Only app-dev, app-prod, database, ingress, agent, s3, metrics, analytics, and gateway use host-key fingerprint pinning.',
            );
        }

        if (! $needsHost && $this->stringOption('gateway-endpoint') !== null) {
            return $this->validationFailed(
                'gateway_endpoint',
                'Only app-dev, app-prod, database, ingress, agent, s3, metrics, analytics, and gateway use WireGuard endpoint overrides.',
            );
        }

        $host = $needsHost ? $this->resolveHost() : null;

        if ($needsHost && $host === null) {
            return $this->validationFailed('host', 'Host is required for workload roles that provision a host.');
        }

        if ($host !== null && ! $this->isValidHost($host)) {
            return $this->validationFailed('host', 'Host must be a valid IP address or dotted DNS name.');
        }

        $gatewayEndpoint = $this->stringOption('gateway-endpoint');

        if ($gatewayEndpoint !== null && ! $this->isValidHost($gatewayEndpoint)) {
            return $this->validationFailed(
                'gateway_endpoint',
                'Gateway endpoint must be a valid IP address or dotted DNS name.',
            );
        }

        $platform = $this->stringOption('platform');
        $architecture = $this->stringOption('architecture');

        if ($requireObservedPlatform && $platform === null) {
            return $this->validationFailed(
                'platform',
                'Client-observed target platform is required for workload bootstrap.',
            );
        }

        if ($requireObservedPlatform && $architecture === null) {
            return $this->validationFailed(
                'architecture',
                'Client-observed target architecture is required for workload bootstrap.',
            );
        }

        $platform ??= 'ubuntu_24-04';
        $architecture ??= 'amd64';

        if (! in_array($platform, ['ubuntu_24-04', 'ubuntu_26-04'], true)) {
            return $this->validationFailed(
                'platform',
                'Workload bootstrap supports Ubuntu 24.04 and Ubuntu 26.04 targets.',
            );
        }

        if (! in_array($architecture, ['amd64', 'arm64'], true)) {
            return $this->validationFailed(
                'architecture',
                'Workload bootstrap supports amd64 and arm64 targets.',
            );
        }

        $tld = $this->stringOption('tld');

        if ($tld === null) {
            return $this->validationFailed('tld', 'Every node requires a unique TLD.');
        }

        if (! NodeTld::isValid($tld)) {
            return $this->validationFailed(
                'tld',
                'TLD must be a non-reserved lowercase DNS label without a leading dot.',
            );
        }

        $s3DataPath = $this->resolveS3DataPath($roles);

        if ($s3DataPath instanceof GatewayActionResult) {
            return $s3DataPath;
        }

        $analyticsDatabaseNodes = $this->resolveAnalyticsDatabaseNodes($roles);

        if ($analyticsDatabaseNodes instanceof GatewayActionResult) {
            return $analyticsDatabaseNodes;
        }

        return new WorkloadNodeProvisioningInput(
            host: $host ?? '',
            tld: $tld,
            sshUser: $needsHost ? $this->resolveSshUser() : null,
            gatewayEndpoint: $needsHost ? $gatewayEndpoint : null,
            hostKeyFingerprint: $needsHost ? $this->stringOption('host-key-fingerprint') : null,
            platform: $platform,
            architecture: $architecture,
            postgresNodeId: $analyticsDatabaseNodes['postgres_node_id'],
            postgresProcessId: $analyticsDatabaseNodes['postgres_process_id'],
            clickhouseNodeId: $analyticsDatabaseNodes['clickhouse_node_id'],
            s3DataPath: $s3DataPath,
        );
    }

    /**
     * @param  list<string>  $roles
     */
    private function resolveS3DataPath(array $roles): string|GatewayActionResult|null
    {
        $hasS3 = in_array(NodeRoleName::S3->value, $roles, true);
        $dataPath = $this->stringOption('s3-data-path');

        if (! $hasS3) {
            return $dataPath === null
                ? null
                : $this->validationFailed('s3_data_path', 'Only s3 nodes use --s3-data-path.');
        }

        $dataPath ??= S3RoleSettings::DefaultDataPath;

        try {
            return S3RoleSettings::fromArray(['data_path' => $dataPath])->dataPath;
        } catch (InvalidArgumentException $exception) {
            return $this->validationFailed('s3_data_path', $exception->getMessage());
        }
    }

    /**
     * @param  list<string>  $roles
     * @return array{postgres_node_id: ?int, postgres_process_id: ?int, clickhouse_node_id: ?int}|GatewayActionResult
     */
    private function resolveAnalyticsDatabaseNodes(array $roles): array|GatewayActionResult
    {
        $hasAnalytics = in_array(NodeRoleName::Analytics->value, $roles, true);
        $postgresNodeName = $this->stringOption('postgres-node');
        $postgresProcessName = $this->stringOption('postgres-process');
        $clickhouseNodeName = $this->stringOption('clickhouse-node');

        if (! $hasAnalytics) {
            if ($postgresNodeName !== null) {
                return $this->validationFailed('postgres_node', 'Only analytics nodes use --postgres-node.');
            }

            if ($clickhouseNodeName !== null) {
                return $this->validationFailed('clickhouse_node', 'Only analytics nodes use --clickhouse-node.');
            }

            if ($postgresProcessName !== null) {
                return $this->validationFailed('postgres_process', 'Only analytics nodes use --postgres-process.');
            }

            return [
                'postgres_node_id' => null,
                'postgres_process_id' => null,
                'clickhouse_node_id' => null,
            ];
        }

        if ($postgresNodeName === null) {
            return $this->validationFailed('postgres_node', 'Analytics nodes require --postgres-node.');
        }

        if ($clickhouseNodeName === null) {
            return $this->validationFailed('clickhouse_node', 'Analytics nodes require --clickhouse-node.');
        }

        if ($postgresProcessName === null) {
            return $this->validationFailed('postgres_process', 'Analytics nodes require --postgres-process.');
        }

        $postgresNode = $this->findActiveDatabaseNodeByName($postgresNodeName);

        if (! $postgresNode instanceof Node) {
            return $this->validationFailed(
                'postgres_node',
                'Analytics nodes require an active database node for PostgreSQL.',
            );
        }

        $postgresProcess = Process::query()
            ->where('owner_type', $postgresNode->getMorphClass())
            ->where('owner_id', $postgresNode->getKey())
            ->where('name', $postgresProcessName)
            ->where('runtime_config->service', 'postgres')
            ->first();

        if (
            ! $postgresProcess instanceof Process
            || ! $this->analyticsDatabaseResolver->isPlausiblePostgresProcess($postgresProcess)
        ) {
            return $this->validationFailed(
                'postgres_process',
                'Analytics nodes require a PostgreSQL 16 process for Plausible on the assigned database node.',
            );
        }

        $clickhouseNode = $this->findActiveDatabaseNodeByName($clickhouseNodeName);

        if (! $clickhouseNode instanceof Node) {
            return $this->validationFailed(
                'clickhouse_node',
                'Analytics nodes require an active database node for ClickHouse.',
            );
        }

        return [
            'postgres_node_id' => $postgresNode->id,
            'postgres_process_id' => $postgresProcess->id,
            'clickhouse_node_id' => $clickhouseNode->id,
        ];
    }

    /**
     * @param  list<string>  $roles
     */
    private function containsAppWorkloadRole(array $roles): bool
    {
        return (
            array_intersect($roles, [
                NodeRoleName::AppDevelopment->value,
                NodeRoleName::AppProduction->value,
            ]) !== []
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsForRole(
        string $role,
        ?int $postgresNodeId = null,
        ?int $postgresProcessId = null,
        ?int $clickhouseNodeId = null,
        ?string $s3DataPath = null,
    ): array {
        if ($role === NodeRoleName::Analytics->value) {
            return [
                'postgres_node_id' => $postgresNodeId,
                'postgres_process_id' => $postgresProcessId,
                'clickhouse_node_id' => $clickhouseNodeId,
            ];
        }

        if ($role === NodeRoleName::S3->value) {
            return [
                'data_path' => $s3DataPath ?? S3RoleSettings::DefaultDataPath,
            ];
        }

        return [];
    }

    /**
     * @param  list<string>  $roles
     * @param  array{postgres?: int|null, postgres_process?: int|null, clickhouse?: int|null}  $backingNodeIds
     */
    private function ensureInitialWorkloadRoles(
        Node $node,
        NodeRoleAssignmentService $roleAssignmentService,
        array $roles,
        ?int $appProductionIngressNodeId = null,
        array $backingNodeIds = [],
    ): ?GatewayActionResult {
        foreach ($this->orderWorkloadRoles($roles) as $role) {
            $existingAssignment = $node->roleAssignments()->where('role', $role)->first();
            $settings = $role === NodeRoleName::AppProduction->value
                ? ['ingress_node_id' => $appProductionIngressNodeId ?? $node->id]
                : $this->settingsForRole(
                    $role,
                    $backingNodeIds['postgres'] ?? null,
                    $backingNodeIds['postgres_process'] ?? null,
                    $backingNodeIds['clickhouse'] ?? null,
                    $this->stringOption('s3-data-path') ?? S3RoleSettings::DefaultDataPath,
                );

            $assignment = $existingAssignment instanceof NodeRoleAssignment
                ? $roleAssignmentService->retryDuringCreation($node, $role, $settings)
                : $roleAssignmentService->addDuringCreation($node, $role, $settings);

            if ($assignment->status !== NodeRoleStatus::Error) {
                continue;
            }

            return $this->failCommand(
                code: 'node.provisioning_incomplete',
                message: "Node '{$node->name}' created but workload role '{$assignment->role}' failed to converge.",
                meta: [
                    'node' => $node->name,
                    'role' => $assignment->role,
                    'status' => $assignment->status->value,
                    'settings' => $assignment->settings ?? [],
                    'last_error' => $assignment->last_error,
                ],
            );
        }

        return null;
    }

    /**
     * @param  list<string>  $roles
     */
    private function setupManagedNode(
        NodeConverger $nodeConverger,
        Node $node,
        array $roles,
    ): ?GatewayActionResult {
        if (! $this->containsDevelopmentAppRole($roles)) {
            return null;
        }

        $freshNode = $node->fresh();

        $result = $nodeConverger->converge(
            node: $freshNode instanceof Node ? $freshNode : $node,
            context: NodeConvergenceContext::Setup,
            families: ['node', 'tool'],
        );

        if ($result->successful()) {
            return null;
        }

        return $this->failCommand(
            code: 'node.provisioning_incomplete',
            message: "Node '{$node->name}' created but managed setup did not complete.",
            meta: [
                'node' => $node->name,
                'step' => 'node_setup',
                'setup' => $result->toArray(),
            ],
        );
    }

    /**
     * @param  list<string>  $roles
     * @return NodeCreationIngressPlacement|GatewayActionResult
     */
    private function resolveIngressPlacement(
        array $roles,
        bool $validateLocalIngressRegistry = true,
    ): NodeCreationIngressPlacement|GatewayActionResult {
        $roles = array_values(array_unique($roles));
        $ingressNodeName = $this->stringOption('ingress');

        if (
            $ingressNodeName !== null
            && (! in_array(NodeRoleName::AppProduction->value, $roles, true)
            || in_array(NodeRoleName::Ingress->value, $roles, true))
        ) {
            return $this->failCommand(
                code: 'validation_failed',
                message: '--ingress is only supported for private app-prod placement.',
                meta: ['field' => 'ingress_node'],
            );
        }

        if (! in_array(NodeRoleName::AppProduction->value, $roles, true)) {
            return new NodeCreationIngressPlacement(
                roles: $roles,
                ingressNodeId: null,
                ingressNodeName: null,
            );
        }

        if (in_array(NodeRoleName::Ingress->value, $roles, true)) {
            return new NodeCreationIngressPlacement(
                roles: $this->orderWorkloadRoles($roles),
                ingressNodeId: null,
                ingressNodeName: null,
            );
        }

        if ($ingressNodeName !== null) {
            if (! $validateLocalIngressRegistry) {
                return new NodeCreationIngressPlacement(
                    roles: $this->orderWorkloadRoles($roles),
                    ingressNodeId: null,
                    ingressNodeName: $ingressNodeName,
                );
            }

            $ingressNode = $this->findActiveIngressNodeByName($ingressNodeName);

            if (! $ingressNode instanceof Node) {
                return $this->missingIngressPlacement();
            }

            return new NodeCreationIngressPlacement(
                roles: $this->orderWorkloadRoles($roles),
                ingressNodeId: $ingressNode->id,
                ingressNodeName: $ingressNode->name,
            );
        }

        return $this->missingIngressPlacement('App-production requires explicit ingress placement.');
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function orderWorkloadRoles(array $roles): array
    {
        usort(
            $roles,
            static fn (string $first, string $second): int => (
                match ($first) {
                    NodeRoleName::Ingress->value => 10,
                    NodeRoleName::AppProduction->value => 20,
                    default => 30,
                } <=> match ($second) {
                    NodeRoleName::Ingress->value => 10,
                    NodeRoleName::AppProduction->value => 20,
                    default => 30,
                }
            ),
        );

        return $roles;
    }

    private function findActiveIngressNodeByName(string $name): ?Node
    {
        return Node::query()
            ->where('name', $name)
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', fn (Builder $query) => $query
                ->where('role', NodeRoleName::Ingress->value)
                ->where('status', NodeRoleStatus::Active->value))
            ->first();
    }

    private function findActiveDatabaseNodeByName(string $name): ?Node
    {
        return Node::query()
            ->where('name', $name)
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', fn (Builder $query) => $query
                ->where('role', NodeRoleName::Database->value)
                ->where('status', NodeRoleStatus::Active->value))
            ->first();
    }

    private function missingIngressPlacement(
        string $message = 'Private app-prod nodes require an active ingress node. Create one first with: orbit node:new edge-1 --template=ingress',
    ): GatewayActionResult {
        return $this->failCommand(
            code: 'validation_failed',
            message: $message,
            meta: [
                'field' => 'ingress_node',
                'required_role' => NodeRoleName::Ingress->value,
            ],
        );
    }

    private function setupAgentSelfGrant(Node $node): ?GatewayActionResult
    {
        $selfGrantMode = $this->stringOption('self-grant') ?? 'default';
        $selfGrantPermissions = $this->stringOption('self-grant-permissions');

        if (! in_array($selfGrantMode, ['default', 'custom'], true)) {
            return $this->validationFailed('self-grant', 'Self-grant mode must be one of default or custom.');
        }

        $materializer = app(RoleSelfGrantMaterializer::class);

        if ($selfGrantMode === 'default') {
            $materializer->materializeOnRoleApplied($node, NodeRoleName::Agent);

            return null;
        }

        $permissions = $this->resolveGrantPermissions(null, $selfGrantPermissions);

        if ($permissions instanceof GatewayActionResult) {
            return $permissions;
        }

        $materializer->replaceCustomSelfPermissions($node, $permissions);

        return null;
    }

    private function setupGrantTo(Node $node): ?GatewayActionResult
    {
        $targets = $this->arrayOption('grant-to');

        if ($targets === []) {
            return null;
        }

        $preset = $this->stringOption('grant-to-preset');
        $permissionsInput = $this->stringOption('grant-to-permissions');

        $permissions = $this->resolveGrantPermissions($preset, $permissionsInput);
        if ($permissions instanceof GatewayActionResult) {
            return $permissions;
        }

        $resolvedTargets = $this->resolveGrantTargets($targets, $node->id);
        if ($resolvedTargets instanceof GatewayActionResult) {
            return $resolvedTargets;
        }

        foreach ($resolvedTargets as $targetNode) {
            NodeAccess::query()->firstOrCreate([
                'consumer_node_id' => $node->id,
                'serving_node_id' => $targetNode->id,
            ], [
                'permissions' => $permissions,
            ]);
        }

        return null;
    }

    private function setupGrantFrom(Node $node): ?GatewayActionResult
    {
        $sources = $this->arrayOption('grant-from');

        if ($sources === []) {
            return null;
        }

        $preset = $this->stringOption('grant-from-preset');
        $permissionsInput = $this->stringOption('grant-from-permissions');

        $permissions = $this->resolveGrantPermissions($preset, $permissionsInput);
        if ($permissions instanceof GatewayActionResult) {
            return $permissions;
        }

        $resolvedSources = $this->resolveGrantTargets($sources, $node->id);
        if ($resolvedSources instanceof GatewayActionResult) {
            return $resolvedSources;
        }

        foreach ($resolvedSources as $sourceNode) {
            NodeAccess::query()->firstOrCreate([
                'consumer_node_id' => $sourceNode->id,
                'serving_node_id' => $node->id,
            ], [
                'permissions' => $permissions,
            ]);
        }

        return null;
    }

    /**
     * @param  array<int, string>  $options
     * @return list<Node>|GatewayActionResult
     */
    private function resolveGrantTargets(
        array $options,
        ?int $excludeNodeId = null,
    ): array|GatewayActionResult {
        $targets = [];
        $hasAll = false;

        foreach ($options as $option) {
            if (! is_string($option) || $option === '') {
                continue;
            }

            if ($option === 'all') {
                $hasAll = true;

                continue;
            }

            $targetNode = Node::query()
                ->where('name', $option)
                ->where('status', NodeStatus::Active->value)
                ->first();

            if (! $targetNode instanceof Node) {
                return $this->failCommand(
                    code: 'node.not_found',
                    message: "Grant target node '{$option}' not found.",
                    meta: ['node' => $option],
                );
            }

            $targets[] = $targetNode;
        }

        if ($hasAll) {
            $allNodes = Node::query()
                ->where('status', NodeStatus::Active->value)
                ->get();

            foreach ($allNodes as $allNode) {
                if ($excludeNodeId !== null && $allNode->id === $excludeNodeId) {
                    continue;
                }
                $alreadyIncluded = array_any($targets, fn ($target) => $target->id === $allNode->id);

                if (! $alreadyIncluded) {
                    $targets[] = $allNode;
                }
            }
        }

        return $targets;
    }

    /**
     * @return list<string>|GatewayActionResult
     */
    private function resolveGrantPermissions(
        ?string $preset,
        ?string $permissionsInput,
    ): array|GatewayActionResult {
        if ($preset !== null && $permissionsInput !== null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use either --preset or --permissions, not both.',
                meta: ['fields' => ['preset', 'permissions']],
            );
        }

        if ($preset !== null) {
            if ($preset === 'gateway-admin') {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Gateway-admin is not offered by default. Use node:grant with --force to create a gateway-admin grant.',
                    meta: ['field' => 'preset', 'preset' => 'gateway-admin'],
                );
            }

            try {
                return app(NodePermissionPresets::class)->permissions($preset);
            } catch (InvalidArgumentException $e) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: $e->getMessage(),
                    meta: ['field' => 'preset', 'preset' => $preset],
                );
            }
        }

        if ($permissionsInput !== null) {
            $permissions = array_map(trim(...), explode(',', $permissionsInput));
            $permissions = array_values(array_filter($permissions));

            if ($permissions === []) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Permission set cannot be empty.',
                    meta: ['field' => 'permissions'],
                );
            }

            try {
                $normalized = app(NodePermissionNormalizer::class)->normalize($permissions);
            } catch (InvalidArgumentException $e) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: $e->getMessage(),
                    meta: ['field' => 'permissions'],
                );
            }

            return $normalized->permissions;
        }

        return $this->failCommand(
            code: 'validation_failed',
            message: 'Use --preset or --permissions to specify grant permissions.',
            meta: ['fields' => ['preset', 'permissions']],
        );
    }

    /**
     * @param  list<string>  $roles
     */
    private function preflightAgentSetup(array $roles): ?GatewayActionResult
    {
        $hasAgentRole = in_array(NodeRoleName::Agent->value, $roles, true);

        $tools = $this->arrayOption('agent-tool');
        if ($tools !== [] && ! $hasAgentRole) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Agent tools can only be specified for agent nodes.',
                meta: ['field' => 'agent-tool'],
            );
        }

        $selfGrantMode = $this->stringOption('self-grant');
        if ($selfGrantMode !== null && ! in_array($selfGrantMode, ['default', 'custom'], true)) {
            return $this->validationFailed('self-grant', 'Self-grant mode must be one of default or custom.');
        }

        $selfGrantPermissions = $this->stringOption('self-grant-permissions');
        if ($selfGrantPermissions !== null && $selfGrantMode !== 'custom') {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use --self-grant=custom when supplying --self-grant-permissions.',
                meta: ['fields' => ['self-grant', 'self-grant-permissions']],
            );
        }

        $grantToTargets = $this->arrayOption('grant-to');
        if ($grantToTargets !== []) {
            $resolved = $this->resolveGrantTargets($grantToTargets);
            if ($resolved instanceof GatewayActionResult) {
                return $resolved;
            }
        }

        $grantFromSources = $this->arrayOption('grant-from');
        if ($grantFromSources !== []) {
            $resolved = $this->resolveGrantTargets($grantFromSources);
            if ($resolved instanceof GatewayActionResult) {
                return $resolved;
            }
        }

        $grantToPreset = $this->stringOption('grant-to-preset');
        $grantToPermissions = $this->stringOption('grant-to-permissions');
        if ($grantToTargets === [] && ($grantToPreset !== null || $grantToPermissions !== null)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use --grant-to to specify target nodes when supplying --grant-to-preset or --grant-to-permissions.',
                meta: ['fields' => ['grant-to', 'grant-to-preset', 'grant-to-permissions']],
            );
        }
        if ($grantToTargets !== []) {
            $permissions = $this->resolveGrantPermissions($grantToPreset, $grantToPermissions);
            if ($permissions instanceof GatewayActionResult) {
                return $permissions;
            }
        }

        $grantFromPreset = $this->stringOption('grant-from-preset');
        $grantFromPermissions = $this->stringOption('grant-from-permissions');
        if ($grantFromSources === [] && ($grantFromPreset !== null || $grantFromPermissions !== null)) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use --grant-from to specify source nodes when supplying --grant-from-preset or --grant-from-permissions.',
                meta: ['fields' => ['grant-from', 'grant-from-preset', 'grant-from-permissions']],
            );
        }
        if ($grantFromSources !== []) {
            $permissions = $this->resolveGrantPermissions($grantFromPreset, $grantFromPermissions);
            if ($permissions instanceof GatewayActionResult) {
                return $permissions;
            }
        }

        if ($selfGrantMode === 'custom' || $selfGrantPermissions !== null) {
            $permissions = $this->resolveGrantPermissions(null, $selfGrantPermissions);
            if ($permissions instanceof GatewayActionResult) {
                return $permissions;
            }
        }

        if ($hasAgentRole && $tools !== []) {
            $catalog = app(ToolCatalog::class);
            foreach ($tools as $tool) {
                if (! $catalog->supports($tool)) {
                    return $this->failCommand(
                        code: 'validation_failed',
                        message: "Unknown agent tool '{$tool}'.",
                        meta: ['field' => 'agent-tool', 'tool' => $tool],
                    );
                }

                if ($catalog->category($tool) !== 'agent') {
                    return $this->failCommand(
                        code: 'validation_failed',
                        message: "Tool '{$tool}' is not an agent tool.",
                        meta: ['field' => 'agent-tool', 'tool' => $tool],
                    );
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{code: string, tools: list<string>}>  $warnings
     */
    private function setupAgentTools(Node $node, array &$warnings): ?GatewayActionResult
    {
        $tools = $this->arrayOption('agent-tool');

        if ($tools === []) {
            return null;
        }

        $resolvedTools = [];

        foreach ($tools as $tool) {
            $catalog = app(ToolCatalog::class);

            if (! $catalog->supports($tool)) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: "Unknown agent tool '{$tool}'.",
                    meta: ['field' => 'agent-tool', 'tool' => $tool],
                );
            }

            if ($catalog->category($tool) !== 'agent') {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: "Tool '{$tool}' is not an agent tool.",
                    meta: ['field' => 'agent-tool', 'tool' => $tool],
                );
            }

            $resolvedTools[] = $tool;
        }

        if (count($resolvedTools) > 1) {
            $warnings[] = [
                'code' => 'tool.multiple_agent_tools_running',
                'tools' => $resolvedTools,
            ];
        }

        foreach ($resolvedTools as $tool) {
            $result = app(ToolInstaller::class)->install($tool, $node->name, null, 'installed');

            if ($result instanceof ToolRegistryFailure) {
                return $this->failCommand(
                    code: $result->code,
                    message: $result->message,
                    meta: $result->meta,
                );
            }
        }

        return null;
    }

    private function isValidNodeName(string $name): bool
    {
        return (bool) preg_match('/^[a-z](?:[a-z0-9-]*[a-z0-9])?$/', $name);
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
        return app(NodeRoleAssignments::class)->activeGatewayNodeQuery();
    }

    /**
     * @param  array<int, string>  $excluding
     */
    private function resolveProvisionedNodeWireguardAddress(
        array $excluding = [],
    ): string|GatewayActionResult {
        $reservedAddress = $this->e2eReservedWireguardAddress();

        if ($reservedAddress === null) {
            return $this->nextWireguardAddress($excluding);
        }

        if (! $this->isManagedWireguardAddress($reservedAddress)) {
            return $this->validationFailed(
                'wireguard_address',
                'Prepared topology WireGuard address must be in the managed 10.6.0.3-10.6.0.254 range.',
            );
        }

        if (in_array($reservedAddress, $this->usedWireguardAddresses($excluding), true)) {
            return $this->failCommand(
                code: 'node.incompatible',
                message: "WireGuard address '{$reservedAddress}' is already assigned.",
                meta: [
                    'field' => 'wireguard_address',
                    'value' => $reservedAddress,
                ],
            );
        }

        return $reservedAddress;
    }

    /**
     * @param  array<int, string>  $excluding
     */
    private function nextWireguardAddress(array $excluding = []): string
    {
        $used = $this->usedWireguardAddresses($excluding);

        for ($octet = 3; $octet <= 254; $octet++) {
            $candidate = "10.6.0.{$octet}";

            if (! in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        throw new RuntimeException('No available WireGuard addresses remain in 10.6.0.0/24.');
    }

    /**
     * @param  array<int, string>  $excluding
     * @return list<string>
     */
    private function usedWireguardAddresses(array $excluding = []): array
    {
        $used = Node::query()
            ->whereNotNull('wireguard_address')
            ->pluck('wireguard_address')
            ->all();

        $peerAddresses = WireGuardPeer::query()
            ->whereNotNull('allowed_ips')
            ->pluck('allowed_ips')
            ->flatMap(fn (string $allowedIps): array => $this->wireguardAddressesFromAllowedIps($allowedIps))
            ->all();

        $wgEasyAddresses = app(WgEasyAddressReservationProbe::class)->addresses();

        return array_values(array_unique(array_merge($used, $peerAddresses, $wgEasyAddresses, $excluding)));
    }

    /**
     * @return list<string>
     */
    private function wireguardAddressesFromAllowedIps(string $allowedIps): array
    {
        return array_values(array_filter(
            array_map(
                fn (string $allowedIp): string => trim(explode('/', trim($allowedIp), 2)[0]),
                explode(',', $allowedIps),
            ),
            fn (string $address): bool => $address !== '',
        ));
    }

    private function e2eReservedWireguardAddress(): ?string
    {
        $e2e = getenv('ORBIT_E2E');

        if (! is_string($e2e) || $e2e === '' || $e2e === '0') {
            return null;
        }

        $address = getenv('ORBIT_E2E_NODE_WIREGUARD_ADDRESS');

        if (! is_string($address) || trim($address) === '') {
            return null;
        }

        return trim($address);
    }

    private function isManagedWireguardAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $parts = array_map(intval(...), explode('.', $address));

        return $parts[0] === 10 && $parts[1] === 6 && $parts[2] === 0 && $parts[3] >= 3 && $parts[3] <= 254;
    }

    private function validationFailed(string $field, string $message): GatewayActionResult
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: $message,
            meta: ['field' => $field],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function jsonSuccess(array $data, array $meta = []): GatewayActionResult
    {
        return GatewayActionResult::success($data, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): GatewayActionResult
    {
        return GatewayActionResult::error($code, $message, $meta);
    }

    private function argument(string $key): mixed
    {
        return $this->arguments[$key] ?? null;
    }

    private function option(string $key): mixed
    {
        return $this->arguments["--{$key}"] ?? null;
    }
}
