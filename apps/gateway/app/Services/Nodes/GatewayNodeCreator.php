<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeStatus;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeBootstrap;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Support\GatewayActionResult;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final readonly class GatewayNodeCreator
{
    public function __construct(
        private NodeBootstrapReservation $bootstrapReservation,
        private NodeAgentProvisioning $agentProvisioning,
        private NodeBootstrapCompletion $bootstrapCompletion,
        private LocalGatewayNodeConverger $gatewayConverger,
        private ClientNodeEnroller $clientEnroller,
        private WorkloadNodeCreationResolver $workloadResolver,
        private NodeBootstrapResumer $bootstrapResumer,
    ) {}

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
        return $this->bootstrapResumer->resume($arguments, $caller);
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
                    fn (
                        string $name,
                        array $roles,
                        WorkloadNodeProvisioningInput $inputs,
                        ?int $ingressNodeId,
                    ): GatewayActionResult => $this->bootstrapCompletion->convergePrepared(
                        name: $name,
                        roles: $roles,
                        inputs: $inputs,
                        appProductionIngressNodeId: $ingressNodeId,
                        bootstrap: $lockedBootstrap,
                        input: $input,
                    ),
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
            $workloadRequest = $this->workloadResolver->resolve(
                $requestedRoles->workloadRoles,
                $input,
                $requireObservedPlatform,
            );

            if ($workloadRequest instanceof GatewayActionResult) {
                return $workloadRequest;
            }

            if (! $gatewayConfigured) {
                return $this->failCommand(
                    code: 'gateway_unavailable',
                    message: 'Gateway connection is required before creating workload nodes.',
                    meta: ['requested_role' => $requestedRoles->requestedRoleMeta],
                );
            }

            if ($this->containsAppWorkloadRole($workloadRequest->roles)) {
                return $provisionWorkload(
                    $name,
                    $workloadRequest->roles,
                    $workloadRequest->inputs,
                    $workloadRequest->ingressNodeId,
                );
            }

            return $this->provisionWorkloadRoleNode(
                roleAssignmentService: $nodeRoleAssignmentService,
                name: $name,
                roles: $workloadRequest->roles,
                inputs: $workloadRequest->inputs,
                appProductionIngressNodeId: $workloadRequest->ingressNodeId,
                provisionWorkload: $provisionWorkload,
                input: $input,
            );
        }

        if ($requestedRoles->clientIdentity || $requestedRoles->operator) {
            return $this->clientEnroller->enroll($name, $requestedRoles->operator, $input);
        }

        if ($requestedRoles->gateway) {
            return $this->gatewayConverger->converge($name, $input);
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

    /** @param list<string> $roles */
    private function containsAppWorkloadRole(array $roles): bool
    {
        return (
            array_intersect($roles, [
                NodeRoleName::AppDevelopment->value,
                NodeRoleName::AppProduction->value,
            ]) !== []
        );
    }

    private function isValidNodeName(string $name): bool
    {
        return (bool) preg_match('/^[a-z](?:[a-z0-9-]*[a-z0-9])?$/', $name);
    }

    private function gatewayQuery(): Builder
    {
        return app(NodeRoleAssignments::class)->activeGatewayNodeQuery();
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
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): GatewayActionResult
    {
        return GatewayActionResult::error($code, $message, $meta);
    }
}
