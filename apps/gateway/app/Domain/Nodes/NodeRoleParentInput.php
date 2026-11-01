<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationSecretValue;
use App\Domain\Routes\RouteExecutionProof;
use App\Infrastructure\Nodes\NodeRoleRuntimeSource;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Admission capture and names-only copies shared by the two owning role parents. */
final readonly class NodeRoleParentInput
{
    /** @return array{snapshot:array<string,mixed>,intent:array<string,mixed>,participant_node_ids:list<int>} */
    public function target(Node $node, NodeRole $assignment, bool $created): array
    {
        app(NodeRoleRuntimeInput::class)->prepare($node, $assignment);
        $snapshot = app(NodeRoleDirectInput::class)->capture($node->refresh(), $assignment, $created);
        $native = $this->contribution($node, $assignment, $snapshot);
        $snapshot['certificates'] = $native['intent']['certificates'] ?? $snapshot['certificates'];

        return ['snapshot' => $snapshot, ...$native];
    }

    /** The parent owns residue; the shared forward child keeps its existing contract.
     * @param  array<string,mixed>  $snapshot
     * @return array{intent:array<string,mixed>,participant_node_ids:list<int>}
     */
    public function contribution(Node $node, NodeRole $assignment, array $snapshot): array
    {
        $contribution = $snapshot;
        $footprint = ValidatedData::object($snapshot['footprint']);
        $residue = array_find(RouteExecutionProof::objects($footprint['artifacts']), static fn (array $row): bool => $row['name'] === 'route-residue' && $row['required'] === true);
        if ($residue !== null) {
            $footprint['artifacts'] = array_values(array_filter(RouteExecutionProof::objects($footprint['artifacts']), static fn (array $row): bool => $row['name'] !== 'route-residue'));
            $payloads = ValidatedData::object($footprint['payloads']);
            unset($payloads['route-residue']);
            $footprint['payloads'] = $payloads;
            $contribution['footprint'] = $footprint;
        }
        $native = app(NativeNodeRoleConvergeInput::class)->capture($node, $assignment, $contribution);
        if ($residue !== null) {
            $native['intent']['residue'] = ['ssh' => $snapshot['ssh'], 'residue' => ValidatedData::object(ValidatedData::object($snapshot['footprint'])['payloads'])['route-residue']];
        }

        return $native;
    }

    /** @param array<string,mixed> $snapshot
     * @return array<string,array{vault:string,key:string}>
     */
    public function executionCopies(Operation $operation, Node $node, array $snapshot): array
    {
        $private = ['observer' => new OperationSecretValue($node->agent_secret_hash)];
        if ($snapshot['runtime'] !== null) {
            $material = app(NodeRoleRuntimeSource::class)->resolveRoleMaterial($snapshot, $node->id);
            $private['runtime_material'] = new OperationSecretValue(json_encode($material->value, JSON_THROW_ON_ERROR));
        }
        $copies = [];
        foreach ($private as $name => $value) {
            $copies[$name] = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'node-role-'.$operation->id.'-'.$name, ValidatedData::string($value->value));
        }

        return $copies;
    }
}
