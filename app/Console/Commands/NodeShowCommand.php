<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\PromptsForRegistryEntities;
use App\Console\Commands\Concerns\RendersShowDetails;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\ShowNodeRequest;
use App\Http\Gateway\Responses\Nodes\NodeShowResponse;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\NodeAgentIdeDefaults;
use App\Services\Nodes\Roles\NodeRoleAssignmentPayload;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

#[Signature('node:show
    {name? : Node name to inspect}
    {--json : Output JSON}')]
#[Description('Show node details from the gateway registry')]
class NodeShowCommand extends Command
{
    use PromptsForRegistryEntities;
    use RendersShowDetails;

    public function handle(): int
    {
        $isGateway = (bool) config('orbit.is_gateway', false);

        $name = $this->resolveName($isGateway);

        if ($name instanceof GatewayApiException) {
            return $this->failCommand(
                code: $name->errorCode() ?? 'gateway_unavailable',
                message: $name->getMessage() !== '' ? $name->getMessage() : 'Gateway connection is required to list nodes.',
                meta: $name->errorMeta(),
            );
        }

        if ($name === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Node name is required.',
                meta: ['field' => 'name'],
            );
        }

        if (! $isGateway) {
            try {
                /** @var NodeShowResponse $dto */
                $dto = app(GatewayConnector::class)
                    ->send(new ShowNodeRequest($name))
                    ->dto();
            } catch (GatewayApiException $e) {
                return $this->failCommand(
                    code: $e->errorCode() ?? 'gateway_unavailable',
                    message: $e->getMessage() !== ''
                        ? $e->getMessage()
                        : 'Gateway connection is required to show node details.',
                    meta: $e->errorMeta(),
                );
            } catch (Throwable) {
                return $this->failCommand(
                    code: 'gateway_unavailable',
                    message: 'Gateway connection is required to show node details.',
                    meta: [],
                );
            }

            $payload = ['node' => $this->restructureGatewayData(['node' => $dto->node])];

            if ($this->wantsJson()) {
                return $this->jsonSuccess($payload);
            }

            $this->renderHuman($payload['node']);

            return self::SUCCESS;
        }

        $node = Node::query()
            ->with('roleAssignments')
            ->where('name', $name)
            ->where('status', 'active')
            ->first();

        if (! $node instanceof Node) {
            return $this->failCommand(
                code: 'node.not_found',
                message: "Node '{$name}' not found or not visible.",
                meta: ['name' => $name],
            );
        }

        $payload = ['node' => $this->nodePayload($node)];

        if ($this->wantsJson()) {
            return $this->jsonSuccess($payload);
        }

        $this->renderHuman($payload['node']);

        return self::SUCCESS;
    }

    private function resolveName(bool $isGateway): string|GatewayApiException|null
    {
        $name = $this->argument('name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        if ($this->isInteractiveInput()) {
            return $this->promptNodeName();
        }

        $defaultRecord = DB::table('local_node_defaults')->first();

        if ($defaultRecord !== null && is_string($defaultRecord->default_node_name) && $defaultRecord->default_node_name !== '') {
            return $defaultRecord->default_node_name;
        }

        $localName = $isGateway
            ? app(NodeRoleAssignments::class)->activeGatewayNodeQuery()->orderBy('name')->value('name')
            : null;

        if (is_string($localName) && $localName !== '') {
            return $localName;
        }

        return null;
    }

    protected function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    protected function promptNodeName(): string|GatewayApiException
    {
        try {
            return $this->promptForVisibleNode(label: 'Select a node');
        } catch (PromptAborted) {
            return new GatewayApiException('Operation cancelled.', 'validation_failed', []);
        }
    }

    /**
     * @param  array<string, mixed>  $gatewayData
     * @return array<string, mixed>
     */
    private function restructureGatewayData(array $gatewayData): array
    {
        $nodeData = $gatewayData['node'] ?? [];

        return [
            'name' => $nodeData['name'] ?? '',
            'role' => $nodeData['role'] ?? '',
            'status' => $nodeData['status'] ?? 'active',
            'environment' => $nodeData['environment'] ?? null,
            'platform' => $nodeData['platform'] ?? 'unknown',
            'roles' => $this->normalizeRoles($nodeData['roles'] ?? null),
            'addresses' => [
                'wireguard' => $nodeData['wireguard_address']
                    ?? ($nodeData['addresses']['wireguard'] ?? ($nodeData['host'] ?? '')),
            ],
            'agent_ide' => $this->agentIdePayloadFromGatewayData($nodeData),
            'grants' => [
                'consuming_nodes' => $this->normalizeGrantNodes($nodeData['grants']['consuming_nodes'] ?? []),
                'serving_nodes' => $this->normalizeGrantNodes($nodeData['grants']['serving_nodes'] ?? []),
            ],
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     role: string,
     *     status: string,
     *     environment: string|null,
     *     platform: string,
     *     roles: list<array{role: string, status: string, settings: array<string, mixed>|stdClass}>,
     *     addresses: array{wireguard: string},
     *     agent_ide: array{adapter: string|null, source: string},
     *     grants: array{
     *         consuming_nodes: array<int, array{name: string, permissions: list<string>}>,
     *         serving_nodes: array<int, array{name: string, permissions: list<string>}>,
     *     }
     * }
     */
    private function nodePayload(Node $node): array
    {
        $consumingGrants = $node->consumingNodes
            ->map(fn (Node $n): array => [
                'name' => $n->name,
                'permissions' => $this->decodePermissions($n->pivot->permissions ?? null),
            ])
            ->all();

        $servingGrants = $node->servingNodes
            ->map(fn (Node $n): array => [
                'name' => $n->name,
                'permissions' => $this->decodePermissions($n->pivot->permissions ?? null),
            ])
            ->all();

        return [
            'name' => $node->name,
            'role' => $node->role,
            'status' => $node->status,
            'environment' => app(NodeRoleAssignments::class)->activeAppHostEnvironment($node),
            'platform' => $node->platform ?? 'unknown',
            'roles' => $node->roleAssignments->map(fn (NodeRoleAssignment $assignment): array => [
                'role' => $assignment->role,
                'status' => $assignment->status,
                'settings' => $this->normalizeRoleSettings($assignment->settings),
            ])->all(),
            'addresses' => [
                'wireguard' => $node->wireguard_address ?? $node->host,
            ],
            'agent_ide' => NodeAgentIdeDefaults::payloadFor($node),
            'grants' => [
                'consuming_nodes' => $consumingGrants,
                'serving_nodes' => $servingGrants,
            ],
        ];
    }

    /**
     * @param  array{
     *     name: string,
     *     role: string,
     *     status: string,
     *     environment: string|null,
     *     platform: string,
     *     roles: list<array{role: string, status: string, settings: array<string, mixed>|stdClass}>,
     *     addresses: array{wireguard: string},
     *     agent_ide: array{adapter: string|null, source: string},
     *     grants: array{
     *         consuming_nodes: array<int, array{name: string, permissions: list<string>}>,
     *         serving_nodes: array<int, array{name: string, permissions: list<string>}>,
     *     }
     * }  $node
     */
    private function renderHuman(array $node): void
    {
        $rolesLabel = $this->humanRolesLabel($node['roles']);

        $properties = [
            'Role' => $node['role'],
            'Environment' => $node['environment'],
            'Platform' => $node['platform'],
            'WireGuard' => $node['addresses']['wireguard'],
            'Consuming' => $this->humanGrantLabels($node['grants']['consuming_nodes']),
            'Serving' => $this->humanGrantLabels($node['grants']['serving_nodes']),
        ];

        if ($rolesLabel !== null) {
            $properties = [
                'Role' => $node['role'],
                'Roles' => $rolesLabel,
                ...array_slice($properties, 1, preserve_keys: true),
            ];
        }

        $this->renderShowDetails("Node: {$node['name']}", $properties);
    }

    /**
     * @param  array<int, array{name: string, permissions: list<string>}>  $grants
     * @return list<string>
     */
    private function humanGrantLabels(array $grants): array
    {
        return array_map(
            static fn (array $grant): string => "{$grant['name']}: ".implode(', ', $grant['permissions']),
            $grants,
        );
    }

    /**
     * @param  array<string, mixed>  $nodeData
     * @return array{adapter: string|null, source: string}
     */
    private function agentIdePayloadFromGatewayData(array $nodeData): array
    {
        $agentIde = $nodeData['agent_ide'] ?? null;

        if (is_array($agentIde)) {
            return [
                'adapter' => is_string($agentIde['adapter'] ?? null) ? $agentIde['adapter'] : null,
                'source' => is_string($agentIde['source'] ?? null) ? $agentIde['source'] : 'default',
            ];
        }

        return [
            'adapter' => null,
            'source' => 'default',
        ];
    }

    private function humanRolesLabel(mixed $roles): ?string
    {
        if (! is_array($roles) || $roles === []) {
            return null;
        }

        $labels = [];

        foreach ($roles as $role) {
            if (! is_array($role) || ! is_string($role['role'] ?? null) || $role['role'] === '') {
                continue;
            }

            $status = is_string($role['status'] ?? null) ? $role['status'] : 'active';

            $labels[] = $status === 'active'
                ? $role['role']
                : "{$role['role']} ({$status})";
        }

        return $labels === [] ? null : implode(', ', $labels);
    }

    /**
     * @return list<array{role: string, status: string, settings: array<string, mixed>|stdClass}>
     */
    private function normalizeRoles(mixed $roles): array
    {
        if (! is_array($roles)) {
            return [];
        }

        $normalized = [];

        foreach ($roles as $role) {
            if (! is_array($role)) {
                continue;
            }

            $name = $role['role'] ?? null;
            $status = $role['status'] ?? null;

            if (! is_string($name) || ! is_string($status)) {
                continue;
            }

            $normalized[] = [
                'role' => $name,
                'status' => $status,
                'settings' => $this->normalizeRoleSettings($role['settings'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function decodePermissions(mixed $permissions): array
    {
        if ($permissions === null) {
            return ['*'];
        }

        if (is_array($permissions)) {
            return $permissions;
        }

        if (is_string($permissions)) {
            $decoded = json_decode($permissions, associative: true);

            return is_array($decoded) ? $decoded : ['*'];
        }

        return ['*'];
    }

    /**
     * @param  list<mixed>  $grants
     * @return list<array{name: string, permissions: list<string>}>
     */
    private function normalizeGrantNodes(mixed $grants): array
    {
        if (! is_array($grants)) {
            return [];
        }

        $normalized = [];

        foreach ($grants as $grant) {
            if (is_array($grant) && is_string($grant['name'] ?? null)) {
                $permissions = [];
                if (is_array($grant['permissions'] ?? null)) {
                    foreach ($grant['permissions'] as $perm) {
                        if (is_string($perm)) {
                            $permissions[] = $perm;
                        }
                    }
                }
                $normalized[] = [
                    'name' => $grant['name'],
                    'permissions' => $permissions !== [] ? $permissions : ['*'],
                ];
            } elseif (is_string($grant)) {
                $normalized[] = [
                    'name' => $grant,
                    'permissions' => ['*'],
                ];
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function normalizeRoleSettings(mixed $settings): array|stdClass
    {
        return NodeRoleAssignmentPayload::settings($settings);
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
