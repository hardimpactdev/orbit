<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Routes\NativeRouteEnvironmentMaterial;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Existing Instance URL material stays on its one admitted workload worker. No output store. */
final readonly class NodeRemovalEnvironmentSource implements OperationSecretSource
{
    private const int Slots = 61;

    public function name(): string
    {
        return 'node.removal_environment';
    }

    public function targetTypes(): array
    {
        return ['node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        $snapshot = $operation->input['_node_removal'] ?? null;
        $execution = $operation->input['_removal'] ?? null;
        if ($operation->kind !== 'node.remove'
            || $operation->target_type !== 'node' || $operation->status->terminal()
            || ! is_array($snapshot) || ! is_array($execution)
            || ($snapshot['node_id'] ?? null) !== $operation->target_id
            || ($execution['snapshot'] ?? null) !== $snapshot
            || ($operation->input['route_removal'] ?? null) !== ($execution['routes'] ?? null)) {
            return null;
        }

        return $operation->target_id;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $owner = app(DirectOperationPrivateBindings::class)->ownerNode($operation, $parameter);
        $fields = $operation->input['environment_fields'] ?? [];
        $bindings = $operation->input['private_bindings'] ?? [];
        $binding = is_array($bindings) ? ($bindings[$parameter] ?? null) : null;
        $routePlan = $operation->input['route_removal'] ?? [];
        $members = is_array($routePlan) ? ($routePlan['nodes'] ?? []) : [];
        if ($this->ownerId($operation) !== $id || $key !== '' || ! is_array($fields)
            || ($fields[(string) $owner] ?? null) !== $parameter
            || ($operation->input[$parameter] ?? null) !== ['$secret' => $this->name(), 'id' => $id]
            || $binding !== ['owner_node_id' => $owner, 'read_node_ids' => [$owner], 'write_node_ids' => []]
            || ! is_array($members) || ! array_any($members, static fn (mixed $member): bool => is_array($member)
                && ($member['node_id'] ?? null) === $owner && ($member['url_instance_ids'] ?? []) !== [])) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Node removal environment material is bound to its admitted workload worker.', 403);
        }

        return new OperationSecretValue(app(NativeRouteEnvironmentMaterial::class)->forNode($operation, $owner));
    }

    /** @return array<string,string> */
    public static function privateMaterial(): array
    {
        return array_fill_keys(array_map(static fn (int $slot): string => 'removal_environment_'.$slot, range(0, self::Slots - 1)), 'node.removal_environment');
    }

    /** Record only the fixed private fields; unused slots admit no reader.
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public static function bind(array $input, int $rootNodeId): array
    {
        $snapshot = ValidatedData::object($input['_node_removal']);
        $routePlan = ValidatedData::object($input['route_removal']);
        $owners = [];
        foreach (is_array($routePlan['nodes']) ? $routePlan['nodes'] : [] as $raw) {
            $member = ValidatedData::object($raw);
            if (ValidatedData::integerList($member['url_instance_ids']) !== []) {
                $owners[] = ValidatedData::integer($member['node_id']);
            }
        }
        $owners = array_values(array_unique($owners));
        sort($owners);
        if (count($owners) > self::Slots || array_diff($owners, ValidatedData::integerList($input['participant_node_ids'])) !== []) {
            throw new ResourceOperationException('node.removal_participant_limit', 'Route workloads require complete admitted Node participants.', 422);
        }
        $bindings = ValidatedData::object($input['private_bindings'] ?? []);
        foreach (['bootstrap_identity', 'configuration', 'metrics_runtime'] as $field) {
            if (! isset($input[$field])) {
                $bindings[$field] = ['owner_node_id' => $rootNodeId, 'read_node_ids' => [], 'write_node_ids' => []];
            }
        }
        $input['environment_fields'] = [];
        foreach (array_keys(self::privateMaterial()) as $slot => $field) {
            unset($input[$field]);
            $owner = $owners[$slot] ?? $rootNodeId;
            $bindings[$field] = ['owner_node_id' => $owner, 'read_node_ids' => isset($owners[$slot]) ? [$owner] : [], 'write_node_ids' => []];
            if (isset($owners[$slot])) {
                $input['environment_fields'][(string) $owner] = $field;
                $input[$field] = ['$secret' => 'node.removal_environment', 'id' => ValidatedData::integer($snapshot['node_id'])];
            }
        }

        $input['private_bindings'] = $bindings;

        return $input;
    }
}
