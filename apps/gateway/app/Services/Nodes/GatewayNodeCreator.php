<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Data\Nodes\RoleSettings\S3RoleSettings;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeBootstrap;
use App\Models\Process;
use App\Models\WireGuardPeer;
use App\Services\Analytics\AnalyticsDatabaseResolver;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Support\GatewayActionResult;
use App\Services\Vpn\WgEasyAddressReservationProbe;
use App\Services\WireGuard\WireGuardKeyGenerator;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Nodes\NodeTld;
use RuntimeException;

final class GatewayNodeCreator
{
    public function __construct(
        private readonly AnalyticsDatabaseResolver $analyticsDatabaseResolver,
        private readonly DnsmasqReconciler $dnsmasqReconciler,
        private readonly NodeBootstrapReservation $bootstrapReservation,
        private readonly NodeAgentProvisioning $agentProvisioning,
        private readonly NodeBootstrapCompletion $bootstrapCompletion,
    ) {}

    private const string DEFAULT_RUNTIME_USER = 'orbit';

    private const int SUCCESS = 0;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function create(array $arguments): GatewayActionResult
    {
        $input = new NodeCreationInput($arguments);

        return $this->execute(
            $input,
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
        $input = new NodeCreationInput($arguments);

        return $this->execute(
            $input,
            fn (
                string $name,
                array $_roles,
                WorkloadNodeProvisioningInput $inputs,
                ?int $_ingressNodeId,
            ): GatewayActionResult => $this->bootstrapReservation->prepare(
                name: $name,
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
        return $this->bootstrapCompletion->complete(
            $bootstrap,
            $caller,
            function (NodeBootstrap $lockedBootstrap): GatewayActionResult {
                $input = new NodeCreationInput([...$lockedBootstrap->request, '--json' => true]);

                return $this->execute(
                    $input,
                    function (
                        string $name,
                        array $roles,
                        WorkloadNodeProvisioningInput $inputs,
                        ?int $ingressNodeId,
                    ) use ($lockedBootstrap, $input): GatewayActionResult {
                        return $this->bootstrapCompletion->convergePrepared(
                            name: $name,
                            roles: $roles,
                            inputs: $inputs,
                            appProductionIngressNodeId: $ingressNodeId,
                            bootstrap: $lockedBootstrap,
                            input: $input,
                        );
                    },
                    requireObservedPlatform: false,
                );
            },
        );
    }

    /**
     * @param  callable(string, list<string>, WorkloadNodeProvisioningInput, ?int): GatewayActionResult  $provisionWorkload
     */
    private function execute(
        NodeCreationInput $input,
        callable $provisionWorkload,
        bool $requireObservedPlatform,
    ): GatewayActionResult {
        return $this->handle(
            $input,
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
        NodeCreationInput $input,
        NodeRoleAssignmentService $nodeRoleAssignmentService,
        WireGuardKeyGenerator $wireGuardKeyGenerator,
        callable $provisionWorkload,
        bool $requireObservedPlatform,
    ): GatewayActionResult {
        $name = $input->stringArgument('name');

        try {
            $requestedRoles = app(NodeCreationRoleResolver::class)->resolve(
                template: $input->stringOption('template'),
                operator: (bool) $input->option('operator'),
                roles: $input->stringOption('roles'),
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
            $input->arrayOption('agent-tool') !== []
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
                $input,
            );

            if ($inputs instanceof GatewayActionResult) {
                return $inputs;
            }

            $placement = $this->resolveIngressPlacement(
                $requestedRoles->workloadRoles,
                $input,
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
                input: $input,
            );
        }

        if ($requestedRoles->clientIdentity || $requestedRoles->operator) {
            $forbiddenInput = $this->forbiddenClientIdentityInput($input);

            if ($forbiddenInput !== null) {
                return $this->validationFailed(
                    $forbiddenInput,
                    'Client identities do not use workload or SSH/bootstrap-only input.',
                );
            }

            return $this->enrollClientNode($wireGuardKeyGenerator, $name, $requestedRoles->operator, $input);
        }

        if ($requestedRoles->gateway) {
            return $this->convergeGatewayLocally($name, $input);
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
        NodeCreationInput $input,
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

        $preflight = $this->agentProvisioning->preflight($roles, $input);
        if ($preflight instanceof GatewayActionResult) {
            return $preflight;
        }

        return $provisionWorkload($name, $roles, $inputs, $appProductionIngressNodeId);
    }

    private function convergeGatewayLocally(string $name, NodeCreationInput $input): GatewayActionResult
    {
        $tld = $input->stringOption('tld');

        if ($tld === null || ! NodeTld::isValid($tld)) {
            return $this->validationFailed('tld', 'Every node requires an explicit valid non-reserved TLD.');
        }

        $host = $input->stringOption('host');

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
        NodeCreationInput $input,
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

        $tld = $input->stringOption('tld');

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

    private function forbiddenClientIdentityInput(NodeCreationInput $input): ?string
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
            if ($input->stringOption($option) !== null) {
                return $option;
            }
        }

        foreach (['agent-tool', 'grant-to', 'grant-from'] as $option) {
            if ($input->arrayOption($option) !== []) {
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
            if ($input->stringOption($option) !== null) {
                return $option;
            }
        }

        if ($input->optionWasSupplied('user')) {
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
        NodeCreationInput $input,
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

        if (! $needsHost && $input->stringOption('host') !== null) {
            return $this->validationFailed(
                'host',
                'Only app-dev, app-prod, database, ingress, agent, s3, metrics, analytics, and gateway use host provisioning.',
            );
        }

        if (! $needsHost && $input->stringOption('host-key-fingerprint') !== null) {
            return $this->validationFailed(
                'host_key_fingerprint',
                'Only app-dev, app-prod, database, ingress, agent, s3, metrics, analytics, and gateway use host-key fingerprint pinning.',
            );
        }

        if (! $needsHost && $input->stringOption('gateway-endpoint') !== null) {
            return $this->validationFailed(
                'gateway_endpoint',
                'Only app-dev, app-prod, database, ingress, agent, s3, metrics, analytics, and gateway use WireGuard endpoint overrides.',
            );
        }

        $host = $needsHost ? $input->stringOption('host') : null;

        if ($needsHost && $host === null) {
            return $this->validationFailed('host', 'Host is required for workload roles that provision a host.');
        }

        if ($host !== null && ! $this->isValidHost($host)) {
            return $this->validationFailed('host', 'Host must be a valid IP address or dotted DNS name.');
        }

        $gatewayEndpoint = $input->stringOption('gateway-endpoint');

        if ($gatewayEndpoint !== null && ! $this->isValidHost($gatewayEndpoint)) {
            return $this->validationFailed(
                'gateway_endpoint',
                'Gateway endpoint must be a valid IP address or dotted DNS name.',
            );
        }

        $platform = $input->stringOption('platform');
        $architecture = $input->stringOption('architecture');

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

        $tld = $input->stringOption('tld');

        if ($tld === null) {
            return $this->validationFailed('tld', 'Every node requires a unique TLD.');
        }

        if (! NodeTld::isValid($tld)) {
            return $this->validationFailed(
                'tld',
                'TLD must be a non-reserved lowercase DNS label without a leading dot.',
            );
        }

        $s3DataPath = $this->resolveS3DataPath($roles, $input);

        if ($s3DataPath instanceof GatewayActionResult) {
            return $s3DataPath;
        }

        $analyticsDatabaseNodes = $this->resolveAnalyticsDatabaseNodes($roles, $input);

        if ($analyticsDatabaseNodes instanceof GatewayActionResult) {
            return $analyticsDatabaseNodes;
        }

        return new WorkloadNodeProvisioningInput(
            host: $host ?? '',
            tld: $tld,
            sshUser: $needsHost ? $input->stringOption('user') ?? 'root' : null,
            gatewayEndpoint: $needsHost ? $gatewayEndpoint : null,
            hostKeyFingerprint: $needsHost ? $input->stringOption('host-key-fingerprint') : null,
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
    private function resolveS3DataPath(
        array $roles,
        NodeCreationInput $input,
    ): string|GatewayActionResult|null {
        $hasS3 = in_array(NodeRoleName::S3->value, $roles, true);
        $dataPath = $input->stringOption('s3-data-path');

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
    private function resolveAnalyticsDatabaseNodes(
        array $roles,
        NodeCreationInput $input,
    ): array|GatewayActionResult {
        $hasAnalytics = in_array(NodeRoleName::Analytics->value, $roles, true);
        $postgresNodeName = $input->stringOption('postgres-node');
        $postgresProcessName = $input->stringOption('postgres-process');
        $clickhouseNodeName = $input->stringOption('clickhouse-node');

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
     * @param  list<string>  $roles
     * @return NodeCreationIngressPlacement|GatewayActionResult
     */
    private function resolveIngressPlacement(
        array $roles,
        NodeCreationInput $input,
        bool $validateLocalIngressRegistry = true,
    ): NodeCreationIngressPlacement|GatewayActionResult {
        $roles = array_values(array_unique($roles));
        $ingressNodeName = $input->stringOption('ingress');

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
}
