<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Nodes\GrantNodeRequest;
use App\Http\Gateway\Responses\Gateway\GatewayIdentityResponse;
use App\Http\Gateway\Responses\Nodes\NodeGrantResponse;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Services\Nodes\Access\NodePermissionNormalizer;
use App\Services\Nodes\Access\NodePermissionPresets;
use App\Services\Nodes\Access\NodePermissionRegistry;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use InvalidArgumentException;
use Throwable;

#[Signature('node:grant
    {consuming_node : Name of the node requesting access}
    {serving_node : Name of the node providing access}
    {--preset= : Permission preset to apply (agent-self, operator, read-only, developer, admin, gateway-admin)}
    {--permissions= : Comma-separated list of permissions}
    {--force : Confirm gateway-admin grant without prompting}
    {--json : Output as JSON}')]
#[Description('Grant one node access to another')]
class NodeGrantCommand extends Command
{
    public function handle(): int
    {
        $executionContext = $this->resolveLocalNodeRole();

        if ($executionContext === 'gateway') {
            return $this->handleGatewayLocal();
        }

        if ($executionContext === 'app-host') {
            return $this->forwardAppNodeGrant();
        }

        return $this->forwardGrant();
    }

    private function resolveLocalNodeRole(): string
    {
        if ((bool) config('orbit.is_gateway', false)) {
            return 'gateway';
        }

        try {
            $request = new ShowGatewayIdentityRequest;
            $response = app(GatewayConnector::class)->send($request);

            if ($response->clientError() || $response->serverError()) {
                return 'control';
            }

            /** @var GatewayIdentityResponse $identity */
            $identity = $response->dto();
            $roles = is_array($identity->self['roles'] ?? null) ? $identity->self['roles'] : [];
            $roleNames = array_values(array_filter(
                array_map(
                    static fn (mixed $role): ?string => is_array($role) && is_string($role['role'] ?? null) ? $role['role'] : null,
                    $roles,
                ),
            ));

            if (in_array('gateway', $roleNames, true)) {
                return 'gateway';
            }

            if (array_intersect($roleNames, ['app-dev', 'app-prod']) !== []) {
                return 'app-host';
            }

            return 'control';
        } catch (Throwable) {
            return 'control';
        }
    }

    private function forwardAppNodeGrant(): int
    {
        $consumerName = (string) $this->argument('consuming_node');
        $servingName = (string) $this->argument('serving_node');
        $preset = $this->option('preset');
        $permissions = $this->option('permissions');
        $force = $this->option('force');

        try {
            /** @var NodeGrantResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new GrantNodeRequest(
                    consumingNode: $consumerName,
                    servingNode: $servingName,
                    preset: is_string($preset) ? $preset : null,
                    permissions: is_string($permissions) ? $permissions : null,
                    force: $force,
                ))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to grant node access.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to grant node access.',
                meta: [],
            );
        }

        if ($dto->permissions === null) {
            return $this->failCommand(
                code: 'gateway_response_invalid',
                message: 'Gateway response missing required permissions field.',
                meta: [],
            );
        }

        return $this->respondSuccess($dto->consumingNode, $dto->servingNode, $dto->alreadyGranted, $dto->action, $dto->permissions, $dto->warnings);
    }

    private function forwardGrant(): int
    {
        $consumerName = (string) $this->argument('consuming_node');
        $servingName = (string) $this->argument('serving_node');
        $preset = $this->option('preset');
        $permissions = $this->option('permissions');
        $force = $this->option('force');

        $resolvedPermissions = $this->resolvePermissions($preset, $permissions);
        if (is_int($resolvedPermissions)) {
            return $resolvedPermissions;
        }

        if ($resolvedPermissions === null && ($this->wantsJson() || ! $this->input->isInteractive())) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use --preset or --permissions to specify grant permissions.',
                meta: ['fields' => ['preset', 'permissions']],
            );
        }

        $permissionsFromPrompt = false;

        if ($resolvedPermissions === null && $this->isInteractiveInput()) {
            $resolvedPermissions = $this->promptInteractivePermissions();
            $permissionsFromPrompt = true;
        }

        if ($resolvedPermissions === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use --preset or --permissions to specify grant permissions.',
                meta: ['fields' => ['preset', 'permissions']],
            );
        }

        return $this->sendForwardGrantRequest($consumerName, $servingName, $preset, $permissions, $resolvedPermissions, $permissionsFromPrompt, $force);
    }

    /**
     * @param  list<string>  $resolvedPermissions
     */
    private function sendForwardGrantRequest(string $consumerName, string $servingName, ?string $preset, ?string $permissions, array $resolvedPermissions, bool $permissionsFromPrompt, bool $force): int
    {
        try {
            /** @var NodeGrantResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new GrantNodeRequest(
                    consumingNode: $consumerName,
                    servingNode: $servingName,
                    preset: is_string($preset) ? $preset : null,
                    permissions: $permissionsFromPrompt ? implode(',', $resolvedPermissions) : (is_string($permissions) ? $permissions : null),
                    force: $force,
                ))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to grant node access.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to grant node access.',
                meta: [],
            );
        }

        if ($dto->permissions === null) {
            return $this->failCommand(
                code: 'gateway_response_invalid',
                message: 'Gateway response missing required permissions field.',
                meta: [],
            );
        }

        return $this->respondSuccess($dto->consumingNode, $dto->servingNode, $dto->alreadyGranted, $dto->action, $dto->permissions, $dto->warnings);
    }

    private function handleGatewayLocal(): int
    {
        $consumerName = (string) $this->argument('consuming_node');
        $servingName = (string) $this->argument('serving_node');
        $preset = $this->option('preset');
        $permissionsInput = $this->option('permissions');
        $force = (bool) $this->option('force');

        $consumer = $this->resolveNode($consumerName, 'consuming_node');
        if (is_int($consumer)) {
            return $consumer;
        }

        $serving = $this->resolveNode($servingName, 'serving_node');
        if (is_int($serving)) {
            return $serving;
        }

        $permissions = $this->resolvePermissions($preset, $permissionsInput);
        if (is_int($permissions)) {
            return $permissions;
        }

        if ($permissions === null && ($this->wantsJson() || ! $this->input->isInteractive())) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use --preset or --permissions to specify grant permissions.',
                meta: ['fields' => ['preset', 'permissions']],
            );
        }

        if ($permissions === null && $this->isInteractiveInput()) {
            $permissions = $this->promptInteractivePermissions();
        }

        $originalPermissions = $permissions;

        if ($permissions !== null) {
            $normalized = app(NodePermissionNormalizer::class)->normalize($permissions);
            $permissions = $normalized->permissions;
        }

        $isGatewayAdmin = $permissions !== null
            && in_array('*', $permissions, true)
            && app(NodeRoleAssignments::class)->nodeIsGateway($serving);

        if ($isGatewayAdmin && ! $force) {
            if (! $this->isInteractiveInput()) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Use --force to create a gateway-admin grant.',
                    meta: ['field' => 'force'],
                );
            }

            $confirmed = \Laravel\Prompts\confirm(
                label: "Create a gateway-admin grant from '{$consumerName}' to '{$servingName}'? This grants fleet-wide super-admin authority.",
                default: false,
            );

            if (! $confirmed) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: 'Operation cancelled.',
                    meta: [],
                );
            }
        }

        if ($permissions === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use --preset or --permissions to specify grant permissions.',
                meta: ['fields' => ['preset', 'permissions']],
            );
        }

        $grant = NodeAccess::query()->firstOrCreate([
            'consumer_node_id' => $consumer->id,
            'serving_node_id' => $serving->id,
        ], [
            'permissions' => $permissions,
        ]);

        $alreadyGranted = ! $grant->wasRecentlyCreated;
        $action = 'granted';

        $warnings = [];

        if ($originalPermissions !== null && $grant->wasRecentlyCreated) {
            $normalized = app(NodePermissionNormalizer::class)->normalize($originalPermissions);
            if ($normalized->removed !== []) {
                $warnings[] = [
                    'code' => 'node.redundant_permissions',
                    'family' => 'node',
                    'message' => 'Redundant permissions were removed: '.implode(', ', $normalized->removed).'.',
                    'next_command' => null,
                    'permissions' => $normalized->removed,
                ];
            }
        }

        return $this->respondSuccess($consumerName, $servingName, $alreadyGranted, $action, $grant->permissions, $warnings);
    }

    /**
     * @param  list<string>|null  $permissions
     * @param  list<array{code: string, family: string, message: string, next_command: string|null, permissions: list<string>}>  $warnings
     */
    private function respondSuccess(string $consumerName, string $servingName, bool $alreadyGranted, string $action, ?array $permissions = null, array $warnings = []): int
    {
        $data = [
            'consuming_node' => $consumerName,
            'serving_node' => $servingName,
            'action' => $action,
            'already_granted' => $alreadyGranted,
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

        if ($alreadyGranted) {
            $this->line("'{$consumerName}' already has access to '{$servingName}'");
            $this->line("Run `orbit node:permissions {$consumerName} {$servingName}` to view or modify permissions.");
        } else {
            $this->line("Granted '{$consumerName}' access to '{$servingName}'");
        }

        if ($permissions !== null) {
            $this->line('Permissions:');
            foreach ($permissions as $permission) {
                $this->line("  - {$permission}");
            }
        }

        foreach ($warnings as $warning) {
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
     * @return list<string>|int|null
     */
    private function resolvePermissions(?string $preset, ?string $permissionsInput): array|int|null
    {
        if ($preset === null && $permissionsInput === null) {
            return null;
        }

        if ($preset !== null && $permissionsInput !== null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use either --preset or --permissions, not both.',
                meta: ['fields' => ['preset', 'permissions']],
            );
        }

        if ($preset !== null) {
            try {
                $permissions = app(NodePermissionPresets::class)->permissions($preset);
            } catch (InvalidArgumentException $e) {
                return $this->failCommand(
                    code: 'validation_failed',
                    message: $e->getMessage(),
                    meta: ['field' => 'preset', 'preset' => $preset],
                );
            }

            return $permissions;
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

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta, ?string $humanMessage = null): int
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

        $output = $humanMessage ?? $message;
        $this->error($output);

        return self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function promptInteractivePermissions(): array
    {
        $allPermissions = app(NodePermissionRegistry::class)->all();
        $selected = \Laravel\Prompts\multiselect(
            label: 'Select permissions',
            options: array_combine($allPermissions, $allPermissions),
            required: true,
        );

        return array_map(strval(...), $selected);
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    private function isInteractiveInput(): bool
    {
        if ($this->wantsJson() || ! $this->input->isInteractive()) {
            return false;
        }

        return (defined('STDIN') && stream_isatty(STDIN))
            || (app()->runningUnitTests() && app()->bound(OutputStyle::class));
    }
}
