<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Routes\NativeNodeRolePublicationInput;
use App\Domain\Routes\RouteRenderContext;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\NodeRole;
use App\Support\ValidatedData;

/** Complete immutable public capture for the standalone 209 role-converge root. */
final readonly class NodeRoleConvergeInput
{
    public function __construct(
        private NodeRoleDirectInput $roles,
        private NativeNodeRolePublicationInput $publications,
        private NodeConvergeInput $footprint,
        private RouteRenderContext $render,
        private SwampSshExecutionInput $ssh,
    ) {}

    /** Runtime intent is prepared by the owning admission transaction before capture.
     *
     * @return array{intent:array<string,mixed>,participant_node_ids:list<int>}
     */
    public function capture(Node $node, NodeRole $assignment): array
    {
        $intent = $this->roles->capture($node, $assignment, false);
        $publication = $this->publications->capture($node, $assignment->role);
        $footprint = $this->render->renderRoleCertificates($publication['certificates'],
            fn (): array => $this->footprint->capture($node, false, false));
        unset($footprint['secret_hash'], $intent['proxy_node_ids']);
        $intent['footprint'] = $footprint;
        $intent['certificates'] = $publication['certificates'];
        $intent['publications'] = $publication['publications'];
        unset($intent['publication']);
        $intent['ssh'] = $this->ssh->forNode($node);
        $prerequisites = ValidatedData::object($intent['prerequisites']);
        $intent['caddy_package'] = in_array('caddy', ValidatedData::stringList($prerequisites['packages']), true)
            ? AgentCaddyPackage::input() : null;

        $metrics = $intent['metrics_fleet'] === null ? null : ValidatedData::object($intent['metrics_fleet']);
        $runtime = ValidatedData::object($intent['runtime'] ?? []);
        $clickhouse = ValidatedData::object($runtime['clickhouse'] ?? []);
        $effectIds = array_values(array_unique([$node->id,
            ...array_map(static fn (array $row): int => ValidatedData::integer($row['node_id']), $publication['publications']),
            ...NodeRoleCertificateInput::participants($publication['certificates']),
            ...NodeRoleMetricsInput::participants($metrics),
            ...(isset($clickhouse['node_id']) ? [ValidatedData::integer($clickhouse['node_id'])] : [])]));
        $ids = array_values(array_unique([209, ...$effectIds]));
        sort($ids);
        $targets = [];
        foreach ($effectIds as $id) {
            $targets[(string) $id] = $this->ssh->forNode(Node::query()->findOrFail($id));
        }
        $intent['targets'] = (object) $targets;

        return ['intent' => $intent, 'participant_node_ids' => $ids];
    }
}
