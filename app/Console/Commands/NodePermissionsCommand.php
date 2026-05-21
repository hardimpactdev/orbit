<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\NodePermissionsRequest;
use App\Http\Gateway\Responses\Nodes\NodePermissionsResponse;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Services\Nodes\Access\NodePermissionNormalizer;
use App\Services\Nodes\Access\NodePermissionPresets;
use App\Services\Nodes\Access\NodePermissionRegistry;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

#[Signature('node:permissions
    {consuming_node? : Name of the consuming node}
    {serving_node? : Name of the serving node}
    {--preset= : Permission preset to apply}
    {--permissions= : Comma-separated list of permissions}
    {--add= : Comma-separated permissions to add}
    {--remove= : Comma-separated permissions to remove}
    {--json : Output as JSON}')]
#[Description('Manage node access permissions')]
class NodePermissionsCommand extends Command
{
    public function handle(NodeRoleAssignments $nodeRoleAssignments): int
    {
        $this->collectInteractiveInputs($nodeRoleAssignments);

        $executionContext = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';

        if ($executionContext === 'control') {
            return $this->forwardPermissions();
        }

        return $this->handleGatewayLocal($nodeRoleAssignments);
    }

    private function collectInteractiveInputs(NodeRoleAssignments $nodeRoleAssignments): void
    {
        if ($this->wantsJson() || ! $this->input->isInteractive() || ! stream_isatty(STDIN)) {
            return;
        }

        $executionContext = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';

        if ($executionContext !== 'gateway') {
            return;
        }

        $activeNodes = Node::query()
            ->where('status', 'active')
            ->pluck('name', 'name')
            ->toArray();

        if ($activeNodes === []) {
            return;
        }

        $consumerName = $this->argument('consuming_node');
        $servingName = $this->argument('serving_node');
        $preset = $this->option('preset');
        $permissions = $this->option('permissions');
        $add = $this->option('add');
        $remove = $this->option('remove');

        $hasModeFlag = $preset !== null || $permissions !== null || $add !== null || $remove !== null;

        if ($consumerName === null) {
            $value = \Laravel\Prompts\select(
                label: 'Consuming node',
                options: $activeNodes,
                required: true,
            );
            $this->input->setArgument('consuming_node', $value);
        }

        if ($servingName === null) {
            $value = \Laravel\Prompts\select(
                label: 'Serving node',
                options: $activeNodes,
                required: true,
            );
            $this->input->setArgument('serving_node', $value);
        }

        if (! $hasModeFlag) {
            $this->promptInteractiveMode();
        }
    }

    private function promptInteractiveMode(): void
    {
        $consumerName = (string) $this->argument('consuming_node');
        $servingName = (string) $this->argument('serving_node');

        $consumer = Node::query()
            ->where('name', $consumerName)
            ->where('status', 'active')
            ->first();

        $serving = Node::query()
            ->where('name', $servingName)
            ->where('status', 'active')
            ->first();

        $grant = null;
        if ($consumer instanceof Node && $serving instanceof Node) {
            $grant = NodeAccess::query()
                ->where('consumer_node_id', $consumer->id)
                ->where('serving_node_id', $serving->id)
                ->first();
        }

        $currentPermissions = $grant !== null ? ($grant->permissions ?? ['*']) : [];

        $allPermissions = app(NodePermissionRegistry::class)->all();
        $selected = \Laravel\Prompts\multiselect(
            label: 'Select permissions',
            options: array_combine($allPermissions, $allPermissions),
            default: $currentPermissions,
            required: false,
        );

        $this->input->setOption('permissions', implode(',', $selected));
    }

    private function forwardPermissions(): int
    {
        $consumerName = $this->argument('consuming_node');
        $servingName = $this->argument('serving_node');
        $preset = $this->option('preset');
        $permissions = $this->option('permissions');
        $add = $this->option('add');
        $remove = $this->option('remove');

        if ($consumerName === null || $servingName === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Both consuming_node and serving_node are required.',
                meta: ['fields' => ['consuming_node', 'serving_node']],
            );
        }

        $modeCount = (int) ($preset !== null) + (int) ($permissions !== null) + (int) ($add !== null) + (int) ($remove !== null);

        if ($modeCount > 1) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use only one of --preset, --permissions, --add, or --remove.',
                meta: [],
            );
        }

        try {
            /** @var NodePermissionsResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new NodePermissionsRequest(
                    consumingNode: is_string($consumerName) ? $consumerName : '',
                    servingNode: is_string($servingName) ? $servingName : '',
                    preset: is_string($preset) ? $preset : null,
                    permissions: is_string($permissions) ? $permissions : null,
                    add: is_string($add) ? $add : null,
                    remove: is_string($remove) ? $remove : null,
                ))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to manage node permissions.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to manage node permissions.',
                meta: [],
            );
        }

        return $this->respondSuccess($dto);
    }

    private function handleGatewayLocal(NodeRoleAssignments $nodeRoleAssignments): int
    {
        $consumerName = $this->argument('consuming_node');
        $servingName = $this->argument('serving_node');
        $preset = $this->option('preset');
        $permissionsOpt = $this->option('permissions');
        $addOpt = $this->option('add');
        $removeOpt = $this->option('remove');

        $modeCount = (int) ($preset !== null) + (int) ($permissionsOpt !== null) + (int) ($addOpt !== null) + (int) ($removeOpt !== null);

        if ($modeCount > 1) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use only one of --preset, --permissions, --add, or --remove.',
                meta: [],
            );
        }

        $isReadMode = $modeCount === 0;

        if ($consumerName === null || $servingName === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Both consuming_node and serving_node are required.',
                meta: ['fields' => ['consuming_node', 'serving_node']],
            );
        }

        $consumerName = (string) $consumerName;
        $servingName = (string) $servingName;

        $consumer = $this->resolveNode($consumerName, 'consuming_node');
        if (is_int($consumer)) {
            return $consumer;
        }

        $serving = $this->resolveNode($servingName, 'serving_node');
        if (is_int($serving)) {
            return $serving;
        }

        $grant = NodeAccess::query()
            ->where('consumer_node_id', $consumer->id)
            ->where('serving_node_id', $serving->id)
            ->first();

        if ($isReadMode) {
            if ($grant === null) {
                return $this->failCommand(
                    code: 'node.grant_not_found',
                    message: "Grant from '{$consumerName}' to '{$servingName}' not found.",
                    meta: [
                        'consuming_node' => $consumerName,
                        'serving_node' => $servingName,
                    ],
                );
            }

            return $this->respondRead($consumerName, $servingName, $grant->permissions ?? ['*']);
        }

        if ($removeOpt !== null) {
            if ($grant === null) {
                return $this->failCommand(
                    code: 'node.grant_not_found',
                    message: "Grant from '{$consumerName}' to '{$servingName}' not found.",
                    meta: [
                        'consuming_node' => $consumerName,
                        'serving_node' => $servingName,
                    ],
                );
            }

            $toRemove = array_map(trim(...), explode(',', $removeOpt));
            $toRemove = array_values(array_filter($toRemove));

            try {
                app(NodePermissionNormalizer::class)->validate($toRemove);
            } catch (InvalidArgumentException $e) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: $e->getMessage(),
                    meta: ['field' => 'remove'],
                );
            }

            $currentPermissions = $grant->permissions ?? ['*'];
            $newPermissions = array_values(array_diff($currentPermissions, $toRemove));

            if ($newPermissions === []) {
                $newPermissions = [];
            }

            $normalized = app(NodePermissionNormalizer::class)->normalize($newPermissions);
            $newPermissions = $normalized->permissions;

            $grant->update(['permissions' => $newPermissions]);

            $warnings = [];

            if ($normalized->removed !== []) {
                $warnings[] = [
                    'code' => 'node.redundant_permissions',
                    'family' => 'node',
                    'message' => 'Redundant permissions were removed: '.implode(', ', $normalized->removed).'.',
                    'next_command' => null,
                    'permissions' => $normalized->removed,
                ];
            }

            return $this->respondMutation($consumerName, $servingName, 'updated', 'remove', $newPermissions, $warnings);
        }

        $permissions = $this->resolveMutationPermissions($preset, $permissionsOpt, $addOpt, $grant);
        if (is_int($permissions)) {
            return $permissions;
        }

        $canCreate = $preset !== null || $permissionsOpt !== null || $addOpt !== null;

        if ($grant === null && ! $canCreate) {
            return $this->failCommand(
                code: 'node.grant_not_found',
                message: "Grant from '{$consumerName}' to '{$servingName}' not found.",
                meta: [
                    'consuming_node' => $consumerName,
                    'serving_node' => $servingName,
                ],
            );
        }

        $normalized = app(NodePermissionNormalizer::class)->normalize($permissions);

        if ($grant === null) {
            NodeAccess::query()->create([
                'consumer_node_id' => $consumer->id,
                'serving_node_id' => $serving->id,
                'permissions' => $normalized->permissions,
            ]);

            $action = 'created';
        } else {
            $grant->update(['permissions' => $normalized->permissions]);
            $action = 'updated';
        }

        $warnings = [];

        if ($normalized->removed !== []) {
            $warnings[] = [
                'code' => 'node.redundant_permissions',
                'family' => 'node',
                'message' => 'Redundant permissions were removed: '.implode(', ', $normalized->removed).'.',
                'next_command' => null,
                'permissions' => $normalized->removed,
            ];
        }

        $mode = $preset !== null ? 'preset' : ($permissionsOpt !== null ? 'permissions' : 'add');

        return $this->respondMutation($consumerName, $servingName, $action, $mode, $normalized->permissions, $warnings);
    }

    /**
     * @return list<string>|int
     */
    private function resolveMutationPermissions(?string $preset, ?string $permissionsOpt, ?string $addOpt, ?NodeAccess $grant): array|int
    {
        if ($preset !== null) {
            if ($preset === '') {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Preset cannot be empty.',
                    meta: ['field' => 'preset'],
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

        if ($permissionsOpt !== null) {
            $permissions = array_map(trim(...), explode(',', $permissionsOpt));
            $permissions = array_values(array_filter($permissions));

            if ($permissions === []) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Permissions cannot be empty.',
                    meta: ['field' => 'permissions'],
                );
            }

            try {
                app(NodePermissionNormalizer::class)->validate($permissions);
            } catch (InvalidArgumentException $e) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: $e->getMessage(),
                    meta: ['field' => 'permissions'],
                );
            }

            return $permissions;
        }

        if ($addOpt !== null) {
            $toAdd = array_map(trim(...), explode(',', $addOpt));
            $toAdd = array_values(array_filter($toAdd));

            if ($toAdd === []) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Add permissions cannot be empty.',
                    meta: ['field' => 'add'],
                );
            }

            try {
                app(NodePermissionNormalizer::class)->validate($toAdd);
            } catch (InvalidArgumentException $e) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: $e->getMessage(),
                    meta: ['field' => 'add'],
                );
            }

            $currentPermissions = $grant !== null ? ($grant->permissions ?? ['*']) : [];
            $merged = array_values(array_unique(array_merge($currentPermissions, $toAdd)));

            return $merged;
        }

        return [];
    }

    private function respondRead(string $consumerName, string $servingName, array $permissions): int
    {
        $data = [
            'consuming_node' => $consumerName,
            'serving_node' => $servingName,
            'action' => 'read',
            'mode' => 'read',
            'permissions' => $permissions,
        ];

        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => ['data' => $data],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line("Permissions for '{$consumerName}' -> '{$servingName}':");
        foreach ($permissions as $permission) {
            $this->line("  - {$permission}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{code: string, family: string, message: string, next_command: string|null, permissions: list<string>}>  $warnings
     */
    private function respondMutation(string $consumerName, string $servingName, string $action, string $mode, ?array $permissions = null, array $warnings = []): int
    {
        $data = [
            'consuming_node' => $consumerName,
            'serving_node' => $servingName,
            'action' => $action,
            'mode' => $mode,
        ];

        if ($permissions !== null) {
            $data['permissions'] = $permissions;
        }

        $payload = ['success' => ['data' => $data]];

        if ($warnings !== []) {
            $payload['success']['meta'] = ['warnings' => $warnings];
        }

        if ($this->wantsJson()) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $message = match ($action) {
            'created' => "Created grant from '{$consumerName}' to '{$servingName}'",
            'updated' => "Updated permissions for '{$consumerName}' -> '{$servingName}'",
            default => "Modified grant from '{$consumerName}' to '{$servingName}'",
        };

        $this->line($message);

        if ($permissions !== null) {
            foreach ($permissions as $permission) {
                $this->line("  - {$permission}");
            }
        }

        foreach ($warnings as $warning) {
            $this->warn($warning['message']);
        }

        return self::SUCCESS;
    }

    private function respondSuccess(NodePermissionsResponse $dto): int
    {
        $data = [
            'consuming_node' => $dto->consumingNode,
            'serving_node' => $dto->servingNode,
            'action' => $dto->action,
        ];

        if ($dto->mode !== null) {
            $data['mode'] = $dto->mode;
        }

        if ($dto->permissions !== null) {
            $data['permissions'] = $dto->permissions;
        }

        $payload = ['success' => ['data' => $data]];

        if ($dto->warnings !== []) {
            $payload['success']['meta'] = ['warnings' => $dto->warnings];
        }

        if ($this->wantsJson()) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $message = match ($dto->action) {
            'created' => "Created grant from '{$dto->consumingNode}' to '{$dto->servingNode}'",
            'updated' => "Updated permissions for '{$dto->consumingNode}' -> '{$dto->servingNode}'",
            default => "Permissions for '{$dto->consumingNode}' -> '{$dto->servingNode}'",
        };

        $this->line($message);

        if ($dto->permissions !== null) {
            foreach ($dto->permissions as $permission) {
                $this->line("  - {$permission}");
            }
        }

        foreach ($dto->warnings as $warning) {
            $this->warn($warning['message']);
        }

        return self::SUCCESS;
    }

    private function resolveNode(string $name, string $field): Node|int
    {
        $node = Node::query()
            ->where('name', $name)
            ->where('status', 'active')
            ->first();

        if (! $node instanceof Node) {
            $label = $field === 'consuming_node' ? 'Consuming' : 'Serving';

            return $this->failCommand(
                code: 'node.not_found',
                message: "{$label} node '{$name}' not found.",
                meta: [
                    'field' => $field,
                    'name' => $name,
                ],
            );
        }

        return $node;
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
                    'meta' => empty($meta) ? (object) [] : $meta,
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
