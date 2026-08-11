<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Services\Nodes\Access\NodePermissionNormalizer;
use App\Services\Nodes\Access\NodePermissionPresets;
use App\Services\Nodes\Roles\RoleSelfGrantMaterializer;
use App\Services\Support\GatewayActionResult;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolInstaller;
use App\Services\Tools\ToolRegistryFailure;
use InvalidArgumentException;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class NodeAgentProvisioning
{
    public function __construct(
        private RoleSelfGrantMaterializer $selfGrantMaterializer,
        private NodePermissionPresets $permissionPresets,
        private NodePermissionNormalizer $permissionNormalizer,
        private ToolCatalog $toolCatalog,
        private ToolInstaller $toolInstaller,
    ) {}

    /**
     * @param  list<string>  $roles
     */
    public function preflight(array $roles, NodeCreationInput $input): ?GatewayActionResult
    {
        $hasAgentRole = in_array(NodeRoleName::Agent->value, $roles, true);
        $tools = $input->arrayOption('agent-tool');

        if ($tools !== [] && ! $hasAgentRole) {
            return GatewayActionResult::error(
                code: 'validation_failed',
                message: 'Agent tools can only be specified for agent nodes.',
                meta: ['field' => 'agent-tool'],
            );
        }

        $selfGrantMode = $input->stringOption('self-grant');

        if ($selfGrantMode !== null && ! in_array($selfGrantMode, ['default', 'custom'], true)) {
            return $this->validationFailed('self-grant', 'Self-grant mode must be one of default or custom.');
        }

        $selfGrantPermissions = $input->stringOption('self-grant-permissions');

        if ($selfGrantPermissions !== null && $selfGrantMode !== 'custom') {
            return GatewayActionResult::error(
                code: 'validation_failed',
                message: 'Use --self-grant=custom when supplying --self-grant-permissions.',
                meta: ['fields' => ['self-grant', 'self-grant-permissions']],
            );
        }

        $grantToTargets = $input->arrayOption('grant-to');

        if ($grantToTargets !== []) {
            $resolved = $this->resolveGrantTargets($grantToTargets);

            if ($resolved instanceof GatewayActionResult) {
                return $resolved;
            }
        }

        $grantFromSources = $input->arrayOption('grant-from');

        if ($grantFromSources !== []) {
            $resolved = $this->resolveGrantTargets($grantFromSources);

            if ($resolved instanceof GatewayActionResult) {
                return $resolved;
            }
        }

        $grantToPreset = $input->stringOption('grant-to-preset');
        $grantToPermissions = $input->stringOption('grant-to-permissions');

        if ($grantToTargets === [] && ($grantToPreset !== null || $grantToPermissions !== null)) {
            return GatewayActionResult::error(
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

        $grantFromPreset = $input->stringOption('grant-from-preset');
        $grantFromPermissions = $input->stringOption('grant-from-permissions');

        if ($grantFromSources === [] && ($grantFromPreset !== null || $grantFromPermissions !== null)) {
            return GatewayActionResult::error(
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

        if ($selfGrantMode === 'custom') {
            $permissions = $this->resolveGrantPermissions(null, $selfGrantPermissions);

            if ($permissions instanceof GatewayActionResult) {
                return $permissions;
            }
        }

        if ($hasAgentRole && $tools !== []) {
            foreach ($tools as $tool) {
                $failure = $this->validateAgentTool($tool);

                if ($failure instanceof GatewayActionResult) {
                    return $failure;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{code: string, tools: list<string>}>  $warnings
     */
    public function apply(Node $node, NodeCreationInput $input, array &$warnings): ?GatewayActionResult
    {
        $failure = $this->setupSelfGrant($node, $input);

        if ($failure instanceof GatewayActionResult) {
            return $failure;
        }

        $failure = $this->setupGrantTo($node, $input);

        if ($failure instanceof GatewayActionResult) {
            return $failure;
        }

        $failure = $this->setupGrantFrom($node, $input);

        if ($failure instanceof GatewayActionResult) {
            return $failure;
        }

        return $this->setupTools($node, $warnings, $input);
    }

    private function setupSelfGrant(Node $node, NodeCreationInput $input): ?GatewayActionResult
    {
        $selfGrantMode = $input->stringOption('self-grant') ?? 'default';
        $selfGrantPermissions = $input->stringOption('self-grant-permissions');

        if (! in_array($selfGrantMode, ['default', 'custom'], true)) {
            return $this->validationFailed('self-grant', 'Self-grant mode must be one of default or custom.');
        }

        if ($selfGrantMode === 'default') {
            $this->selfGrantMaterializer->materializeOnRoleApplied($node, NodeRoleName::Agent);

            return null;
        }

        $permissions = $this->resolveGrantPermissions(null, $selfGrantPermissions);

        if ($permissions instanceof GatewayActionResult) {
            return $permissions;
        }

        $this->selfGrantMaterializer->replaceCustomSelfPermissions($node, $permissions);

        return null;
    }

    private function setupGrantTo(Node $node, NodeCreationInput $input): ?GatewayActionResult
    {
        $targets = $input->arrayOption('grant-to');

        if ($targets === []) {
            return null;
        }

        $permissions = $this->resolveGrantPermissions(
            $input->stringOption('grant-to-preset'),
            $input->stringOption('grant-to-permissions'),
        );

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

    private function setupGrantFrom(Node $node, NodeCreationInput $input): ?GatewayActionResult
    {
        $sources = $input->arrayOption('grant-from');

        if ($sources === []) {
            return null;
        }

        $permissions = $this->resolveGrantPermissions(
            $input->stringOption('grant-from-preset'),
            $input->stringOption('grant-from-permissions'),
        );

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
    private function resolveGrantTargets(array $options, ?int $excludeNodeId = null): array|GatewayActionResult
    {
        $targets = [];
        $hasAll = false;

        foreach ($options as $option) {
            if ($option === '') {
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
                return GatewayActionResult::error(
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

                $alreadyIncluded = array_any($targets, fn (Node $target): bool => $target->id === $allNode->id);

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
            return GatewayActionResult::error(
                code: 'validation_failed',
                message: 'Use either --preset or --permissions, not both.',
                meta: ['fields' => ['preset', 'permissions']],
            );
        }

        if ($preset !== null) {
            if ($preset === 'gateway-admin') {
                return GatewayActionResult::error(
                    code: 'validation_failed',
                    message: 'Gateway-admin is not offered by default. Use node:grant with --force to create a gateway-admin grant.',
                    meta: ['field' => 'preset', 'preset' => 'gateway-admin'],
                );
            }

            try {
                return $this->permissionPresets->permissions($preset);
            } catch (InvalidArgumentException $exception) {
                return GatewayActionResult::error(
                    code: 'validation_failed',
                    message: $exception->getMessage(),
                    meta: ['field' => 'preset', 'preset' => $preset],
                );
            }
        }

        if ($permissionsInput !== null) {
            $permissions = array_values(array_filter(array_map(trim(...), explode(',', $permissionsInput))));

            if ($permissions === []) {
                return GatewayActionResult::error(
                    code: 'validation_failed',
                    message: 'Permission set cannot be empty.',
                    meta: ['field' => 'permissions'],
                );
            }

            try {
                return $this->permissionNormalizer->normalize($permissions)->permissions;
            } catch (InvalidArgumentException $exception) {
                return GatewayActionResult::error(
                    code: 'validation_failed',
                    message: $exception->getMessage(),
                    meta: ['field' => 'permissions'],
                );
            }
        }

        return GatewayActionResult::error(
            code: 'validation_failed',
            message: 'Use --preset or --permissions to specify grant permissions.',
            meta: ['fields' => ['preset', 'permissions']],
        );
    }

    /**
     * @param  list<array{code: string, tools: list<string>}>  $warnings
     */
    private function setupTools(
        Node $node,
        array &$warnings,
        NodeCreationInput $input,
    ): ?GatewayActionResult {
        $tools = $input->arrayOption('agent-tool');

        if ($tools === []) {
            return null;
        }

        foreach ($tools as $tool) {
            $failure = $this->validateAgentTool($tool);

            if ($failure instanceof GatewayActionResult) {
                return $failure;
            }
        }

        if (count($tools) > 1) {
            $warnings[] = [
                'code' => 'tool.multiple_agent_tools_running',
                'tools' => $tools,
            ];
        }

        foreach ($tools as $tool) {
            $result = $this->toolInstaller->install($tool, $node->name, null, 'installed');

            if ($result instanceof ToolRegistryFailure) {
                return GatewayActionResult::error($result->code, $result->message, $result->meta);
            }
        }

        return null;
    }

    private function validateAgentTool(string $tool): ?GatewayActionResult
    {
        if (! $this->toolCatalog->supports($tool)) {
            return GatewayActionResult::error(
                code: 'validation_failed',
                message: "Unknown agent tool '{$tool}'.",
                meta: ['field' => 'agent-tool', 'tool' => $tool],
            );
        }

        if ($this->toolCatalog->category($tool) !== 'agent') {
            return GatewayActionResult::error(
                code: 'validation_failed',
                message: "Tool '{$tool}' is not an agent tool.",
                meta: ['field' => 'agent-tool', 'tool' => $tool],
            );
        }

        return null;
    }

    private function validationFailed(string $field, string $message): GatewayActionResult
    {
        return GatewayActionResult::error(
            code: 'validation_failed',
            message: $message,
            meta: ['field' => $field],
        );
    }
}
