<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\ReenactNodeArtifacts;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\Nodes\NodeStatus;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Requests\Api\UpdateNodeApiRequest;
use App\Models\Node;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Nodes\Roles\NodeRoleToolConfigRefresher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

#[RequiresPermission('node:update', servingNode: ServingNode::Target)]
final class NodeUpdateController implements Loggable
{
    private ?Node $activitySubject = null;

    private ?string $activityTargetName = null;

    /**
     * @var list<string>
     */
    private array $activityChangedFields = [];

    public function __invoke(
        UpdateNodeApiRequest $request,
        string $name,
        ReenactNodeArtifacts $reenactNodeArtifacts,
        NodeRoleToolConfigRefresher $nodeRoleToolConfigRefresher,
    ): JsonResponse {
        $this->activityTargetName = $name;

        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $node = Node::query()
            ->where('name', $name)
            ->where('status', NodeStatus::Active->value)
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

        if ($this->tldIsInUse($node, $providedFields)) {
            return $this->error(
                code: 'node.tld_in_use',
                message: "Node TLD '{$providedFields['tld']}' is already assigned to another node.",
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

        if ($this->touchesDnsFields(array_keys($changes))) {
            app(DnsmasqReconciler::class)->reconcile();
        }

        $node = $node->refresh();
        $nodeRoleToolConfigRefresher->refreshForProvidedNodeFields($node, $providedFields);

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
                'next_command' => 'doctor --family=node --restore',
            ]];
        }
    }

    /**
     * @param  array<string, bool|string>  $providedFields
     * @return array{field: string, role: string}|null
     */
    private function detectRoleIncompatibleField(Node $node, array $providedFields): ?array
    {
        $role = $node->displayRole();
        $restrictedFields = [];

        if ($node->isOperator()) {
            $restrictedFields = ['host', 'gateway_endpoint', 'public_ipv4', 'public_ipv6'];
        }

        if ($node->hasActiveRole('gateway')) {
            $restrictedFields[] = 'user';
        }

        foreach ($restrictedFields as $field) {
            if (array_key_exists($field, $providedFields)) {
                return ['field' => $field, 'role' => $role];
            }
        }

        if (($providedFields['managed'] ?? false) !== true) {
            return null;
        }

        $candidate = clone $node;
        $candidate->managed = true;

        return (
            $candidate->isOperator() && $candidate->isAgentEligible()
                ? null
                : ['field' => 'managed', 'role' => $role]
        );
    }

    /**
     * @param  array<string, bool|string>  $providedFields
     * @return array<string, bool|string>
     */
    private function computeChanges(Node $node, array $providedFields): array
    {
        $changes = [];

        foreach ($providedFields as $field => $value) {
            if ($node->getAttribute($field) === $value) {
                continue;
            }

            $changes[$field] = $value;
        }

        return $changes;
    }

    /**
     * @param  array<string, bool|string>  $providedFields
     */
    private function tldIsInUse(Node $node, array $providedFields): bool
    {
        $tld = $providedFields['tld'] ?? null;

        if (! is_string($tld) || $tld === $node->tld) {
            return false;
        }

        return Node::query()
            ->where('tld', $tld)
            ->where('status', NodeStatus::Active->value)
            ->where('id', '!=', $node->id)
            ->exists();
    }

    /**
     * @param  list<string>  $changedFields
     */
    private function touchesDnsFields(array $changedFields): bool
    {
        return array_intersect(['tld', 'wireguard_address'], $changedFields) !== [];
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
