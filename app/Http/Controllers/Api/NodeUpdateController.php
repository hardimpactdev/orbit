<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\ReenactNodeArtifacts;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Requests\Api\UpdateNodeApiRequest;
use App\Models\Node;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class NodeUpdateController implements Loggable
{
    private ?Node $activitySubject = null;

    private ?string $activityTargetName = null;

    /**
     * @var list<string>
     */
    private array $activityChangedFields = [];

    public function __invoke(UpdateNodeApiRequest $request, string $name, ReenactNodeArtifacts $reenactNodeArtifacts): JsonResponse
    {
        $this->activityTargetName = $name;

        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        if ($caller->role === 'app') {
            return $this->error(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control or gateway node.',
                meta: ['caller_role' => 'app'],
                status: 403,
            );
        }

        if ($caller->role === 'control') {
            $authorization = $this->authorizeControlCaller($caller);

            if ($authorization instanceof JsonResponse) {
                return $authorization;
            }
        }

        $node = Node::query()
            ->where('name', $name)
            ->where('status', 'active')
            ->first();

        if (! $node instanceof Node) {
            return $this->error(
                code: 'node.not_found',
                message: "Node '{$name}' not found.",
                meta: ['name' => $name],
                status: 404,
            );
        }

        $this->activitySubject = $node;

        $providedFields = $request->updateFields();
        $roleIncompatible = $this->detectRoleIncompatibleField($node, $providedFields);

        if ($roleIncompatible !== null) {
            return $this->error(
                code: 'node.field_role_incompatible',
                message: "The field '{$roleIncompatible['field']}' is not valid for node '{$name}' (role: {$roleIncompatible['role']}).",
                meta: [
                    'field' => $roleIncompatible['field'],
                    'name' => $name,
                    'role' => $roleIncompatible['role'],
                ],
                status: 422,
            );
        }

        if (
            isset($providedFields['tld'])
            && $providedFields['tld'] !== $node->tld
            && Node::query()
                ->where('tld', $providedFields['tld'])
                ->where('status', 'active')
                ->where('id', '!=', $node->id)
                ->exists()
        ) {
            return $this->error(
                code: 'node.tld_in_use',
                message: "Development TLD '{$providedFields['tld']}' is already assigned to another node.",
                meta: [
                    'field' => 'tld',
                    'value' => $providedFields['tld'],
                ],
                status: 422,
            );
        }

        $changes = $this->computeChanges($node, $providedFields);
        $this->activityChangedFields = array_keys($changes);

        if ($changes !== []) {
            $node->update($changes);
        }

        $warnings = $this->reenactNodeArtifacts(
            reenactNodeArtifacts: $reenactNodeArtifacts,
            node: $node->refresh(),
            changed: array_keys($changes),
        );

        $success = [
            'data' => [
                'name' => $name,
                'changed' => array_keys($changes),
                'action' => 'updated',
            ],
        ];

        if ($warnings !== []) {
            $success['meta'] = [
                'warnings' => $warnings,
            ];
        }

        return response()->json([
            'success' => $success,
        ]);
    }

    /**
     * @param  list<string>  $changed
     * @return list<array<string, string>>
     */
    private function reenactNodeArtifacts(ReenactNodeArtifacts $reenactNodeArtifacts, Node $node, array $changed): array
    {
        if ($changed === []) {
            return [];
        }

        try {
            return $reenactNodeArtifacts->handle($node, $changed);
        } catch (\Throwable) {
            return [[
                'code' => 'node.artifact_enactment_failed',
                'message' => 'Node artifact re-enactment failed after intent update.',
                'family' => 'node',
                'next_command' => 'doctor --fix --family=node --restore',
            ]];
        }
    }

    private function authorizeControlCaller(Node $caller): ?JsonResponse
    {
        $gateway = Node::query()
            ->where('role', 'gateway')
            ->where('status', 'active')
            ->orderBy('name')
            ->first();

        if (! $gateway instanceof Node) {
            return $this->authorizationFailed(
                message: 'This control node is not authorized to update nodes.',
                meta: [
                    'required_node' => null,
                    'caller_role' => 'control',
                ],
            );
        }

        $hasGatewayAccess = DB::table('node_access')
            ->where('consumer_node_id', $caller->id)
            ->where('serving_node_id', $gateway->id)
            ->exists();

        if ($hasGatewayAccess) {
            return null;
        }

        return $this->authorizationFailed(
            message: 'This control node is not authorized to update nodes.',
            meta: [
                'required_node' => $gateway->name,
                'caller_role' => 'control',
            ],
        );
    }

    /**
     * @param  array<string, string>  $providedFields
     * @return array{field: string, role: string}|null
     */
    private function detectRoleIncompatibleField(Node $node, array $providedFields): ?array
    {
        $role = $node->role;

        if (isset($providedFields['environment']) && $role !== 'app') {
            return ['field' => 'environment', 'role' => $role];
        }

        if (isset($providedFields['host']) && $role === 'control') {
            return ['field' => 'host', 'role' => $role];
        }

        if (isset($providedFields['tld'])) {
            $effectiveEnvironment = $providedFields['environment'] ?? $node->environment;

            if ($role !== 'app' || $effectiveEnvironment !== 'development') {
                return ['field' => 'tld', 'role' => $role];
            }
        }

        if (isset($providedFields['public_ipv4']) && $role === 'control') {
            return ['field' => 'public_ipv4', 'role' => $role];
        }

        if (isset($providedFields['public_ipv6']) && $role === 'control') {
            return ['field' => 'public_ipv6', 'role' => $role];
        }

        return null;
    }

    /**
     * @param  array<string, string>  $providedFields
     * @return array<string, string>
     */
    private function computeChanges(Node $node, array $providedFields): array
    {
        $changes = [];

        if (isset($providedFields['host']) && $providedFields['host'] !== $node->host) {
            $changes['host'] = $providedFields['host'];
        }

        if (isset($providedFields['environment']) && $providedFields['environment'] !== $node->environment) {
            $changes['environment'] = $providedFields['environment'];
        }

        if (isset($providedFields['tld']) && $providedFields['tld'] !== $node->tld) {
            $changes['tld'] = $providedFields['tld'];
        }

        if (isset($providedFields['public_ipv4']) && $providedFields['public_ipv4'] !== $node->public_ipv4) {
            $changes['public_ipv4'] = $providedFields['public_ipv4'];
        }

        if (isset($providedFields['public_ipv6']) && $providedFields['public_ipv6'] !== $node->public_ipv6) {
            $changes['public_ipv6'] = $providedFields['public_ipv6'];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function authorizationFailed(string $message, array $meta = []): JsonResponse
    {
        return $this->error(
            code: 'authorization_failed',
            message: $message,
            meta: $meta,
            status: 403,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:PUT /nodes/{name}';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    public function properties(): array
    {
        return [
            'target_node' => $this->activityTargetName ?? (string) request()->route('name'),
            'changed_fields' => $this->activityChangedFields,
        ];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        $target = $this->activityTargetName ?? (string) request()->route('name');

        return $target !== '' ? "Node {$target} updated" : null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
