<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Domain\Routes\NativeNodeRolePublicationInput;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\MetricsConfigurationRenderer;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
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
        private MetricsConfigurationRenderer $metrics,
        private ServiceMetricsProjection $services,
    ) {}

    /** Runtime intent is prepared by the owning admission transaction before capture.
     *
     * @param  array<string,mixed>|null  $normalizedPublicSettings
     * @return array{intent:array<string,mixed>,participant_node_ids:list<int>}
     */
    public function capture(Node $node, NodeRole $assignment, ?NodeEnrollmentCandidate $candidate = null, ?array $normalizedPublicSettings = null): array
    {
        if (! $node->exists || ! $assignment->exists || $assignment->node_id !== $node->id
            || ! NodeRole::query()->whereKey($assignment->id)->where('node_id', $node->id)->where('role', $assignment->role)->exists()) {
            throw new ResourceOperationException('node.role_input_invalid', 'Role capture requires its actual recorded Node assignment.', 422);
        }
        if ($candidate !== null && $candidate->nodeId !== $node->id) {
            throw new ResourceOperationException('node.role_input_invalid', 'The candidate must name the actual reserved Node.', 422);
        }
        if ($candidate !== null && $candidate->platform !== 'linux') {
            throw new ResourceOperationException('node.platform_unsupported', 'Managed Node roles require Linux.', 422);
        }
        $settingsProvided = func_num_args() >= 4;
        $normalizer = app(NodeSettingsNormalizer::class);
        $settings = $settingsProvided ? $normalizer->storedOver($node->settings, $normalizer->fromStored($normalizedPublicSettings)) : null;
        $capture = fn (): array => $this->captureIntent($node, $assignment, $candidate);
        $scoped = fn (): array => $settingsProvided ? $this->render->renderSettings($node, $settings, $capture) : $capture();

        return $this->render->renderActual(fn (): array => $candidate === null
            ? $scoped() : $this->render->renderEnrollment($candidate, $scoped));
    }

    /** @return array{intent:array<string,mixed>,participant_node_ids:list<int>} */
    private function captureIntent(Node $node, NodeRole $assignment, ?NodeEnrollmentCandidate $candidate): array
    {
        $intent = $this->roles->capture($node, $assignment, false);
        $publication = $this->publications->capture($node, $assignment->role, $candidate);
        $footprint = $this->render->renderRoleCertificates($publication['certificates'],
            fn (): array => $this->footprint->capture($node, false, false));
        unset($footprint['secret_hash'], $intent['proxy_node_ids']);
        $intent['footprint'] = $footprint;
        $intent['certificates'] = $publication['certificates'];
        $intent['publications'] = $publication['publications'];
        unset($intent['publication']);
        $intent['ssh'] = $this->target($node, $candidate);
        $intent['prepared_tools_required'] = $candidate !== null;
        $prerequisites = ValidatedData::object($intent['prerequisites']);
        $intent['caddy_package'] = in_array('caddy', ValidatedData::stringList($prerequisites['packages']), true)
            ? AgentCaddyPackage::input() : null;

        $metrics = $intent['metrics_fleet'] === null ? null : ValidatedData::object($intent['metrics_fleet']);
        $runtime = ValidatedData::object($intent['runtime'] ?? []);
        if (($runtime['role'] ?? null) === RoleName::Metrics->value) {
            $scrapeTargets = $runtime['targets'];
            if (! is_array($scrapeTargets)) {
                throw new \InvalidArgumentException('Metrics runtime targets must be a list.');
            }
            $runtime['public_configuration'] = $this->metrics->publicFiles(array_map(static function (mixed $raw): array {
                $target = ValidatedData::object($raw);

                return ['name' => ValidatedData::string($target['name']), 'address' => ValidatedData::string($target['address'])];
            }, array_values($scrapeTargets)), $this->services->forFleet($node));
            $intent['runtime'] = $runtime;
        }
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
            $targets[(string) $id] = $this->target(Node::query()->findOrFail($id), $candidate?->nodeId === $id ? $candidate : null);
        }
        $intent['targets'] = (object) $targets;

        return ['intent' => $intent, 'participant_node_ids' => $ids];
    }

    /** A null candidate pin requires the parent's verified native binding before any effect.
     * @return array<string,mixed>
     */
    private function target(Node $node, ?NodeEnrollmentCandidate $candidate): array
    {
        if ($candidate === null) {
            return $this->ssh->forNode($node);
        }
        $connection = app(NativeCaddyPublicationInput::class)->connectionForCandidate($node, $candidate);

        return [...$this->ssh->identity(), 'target' => ['name' => 'target', 'host' => $candidate->wireguardIp,
            'user' => $candidate->user, 'port' => 22, 'host_keys' => $connection['host_keys']]];
    }
}
