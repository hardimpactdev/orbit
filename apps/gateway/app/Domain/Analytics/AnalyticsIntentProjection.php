<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Public desired records and bounded observations only. Swamp owns the DAG. */
final readonly class AnalyticsIntentProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function __construct(private DirectOperation $direct, private AnalyticsTrackingIntent $tracking) {}

    public function input(Operation $operation): array
    {
        return [...Arr::only($operation->input, ['instance_id', 'enabled', 'site', 'base_url']),
            'tracking' => $operation->input['tracking'] ?? null,
            ...(! $operation->status->terminal() && isset($operation->input['tracking']) ? $this->ownView($operation) : [])];
    }

    /** @return array<string,mixed> */
    private function ownView(Operation $operation): array
    {
        $plan = $this->tracking->plan($operation);

        if (! in_array($operation->node_id, [...$plan['caddy_node_ids'], ...$plan['dns_node_ids']], true)) {
            return [];
        }
        try {
            return $this->publicInput($operation, $operation->node_id);
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode !== 'analytics.tracking_changed') {
                throw $exception;
            }

            // The root must remain readable so Swamp can project the publication failure.
            return [];
        }
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        $this->tracking->assertCurrent($operation);
        $plan = $this->tracking->plan($operation);
        if ($operation->status->terminal() || ! in_array($callerNodeId,
            [...ValidatedData::integerList($plan['caddy_node_ids']), ...ValidatedData::integerList($plan['dns_node_ids'])], true)) {
            throw new ResourceOperationException('operation.executor_forbidden', 'This Node has no admitted analytics publication.', 403);
        }
    }

    /** GET-only caller-scoped data. The shared boundary authenticates the participant and bound run.
     * @return array<string,mixed>
     */
    public function publicInput(Operation $operation, int $nodeId): array
    {
        $this->tracking->assertCurrent($operation);
        $plan = $this->tracking->plan($operation);
        $caddyIds = ValidatedData::integerList($plan['caddy_node_ids']);
        $dnsIds = ValidatedData::integerList($plan['dns_node_ids']);
        if ($operation->status->terminal() || ! in_array($nodeId, [...$caddyIds, ...$dnsIds], true)) {
            throw new ResourceOperationException('operation.executor_forbidden', 'This Node has no admitted analytics publication.', 403);
        }
        $node = Node::query()->where('status', 'active')->findOrFail($nodeId);
        $view = ['participant_node_id' => $nodeId,
            'certificates' => array_values(array_filter($plan['certificates'], static fn (array $leaf): bool => $leaf['node_id'] === $nodeId)),
            'cleanup' => array_values(array_filter($plan['cleanup'], static fn (array $leaf): bool => $leaf['node_id'] === $nodeId))];
        if (in_array($nodeId, $caddyIds, true)) {
            $render = app(NodeCaddyfileRenderer::class)->render($node);
            $identity = ['node_id' => $nodeId, 'user' => $node->user, 'agent_sha256' => app(AgentDelivery::class)->pin($node)];
            $view['caddy_publication'] = [...$identity, ...($render->buildable()
                ? ['caddy' => ['content' => $render->content, 'version' => $render->version,
                    'listen_addresses' => $render->listenAddresses, 'unserved' => $render->sites === [] || ! CaddySiteRoles::nodeExpectsCaddy($nodeId)]]
                : ['error_code' => 'caddy.render_failed', 'stage' => 'render'])];
        }
        if (in_array($nodeId, $caddyIds, true) && $node->roles()->where('role', RoleName::Ingress)->exists()) {
            $catalog = app(NodeFirewallRuleCatalog::class);
            $desired = $catalog->forRole($node, RoleName::Ingress);
            $comments = array_map(static fn (UfwManagedRule $rule): string => $rule->shape->comment, $desired);
            $removed = array_values(array_filter($catalog->ownedByRole($node, RoleName::Ingress), static fn (UfwManagedRule $rule): bool => ! in_array($rule->shape->comment, $comments, true)));
            $view['ingress_firewall'] = ['desired' => array_map(static fn (UfwManagedRule $rule): array => ['shape' => $rule->shape->toAgentInput(), 'replace_port' => $rule->replacePort], $desired),
                'remove' => array_map(static fn (UfwManagedRule $rule): array => $rule->shape->toAgentInput(), $removed), 'enable' => false];
        }
        $view['public_verification'] = array_values(array_map(
            static fn (array $entry): array => ValidatedData::object($entry['verification']),
            array_filter($plan['public_verifications'], static fn (array $entry): bool => $entry['node_id'] === $nodeId),
        ));
        if (in_array($nodeId, $dnsIds, true)) {
            if (! PrivateDnsPublicationNodes::query()->whereKey($nodeId)->exists()) {
                throw new ResourceOperationException('analytics.tracking_changed', 'The admitted DNS owner changed.', 409);
            }
            $dns = app(DevelopmentDnsConfigRenderer::class);
            $catalog = json_encode(['requesters' => $dns->registeredRequesters(), ...$dns->catalog()->toPublished()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            $view['private_dns_publication'] = ['node_id' => $nodeId, 'records' => $dns->render(), 'catalog' => $catalog, 'digest' => hash('sha256', $catalog)];
        }

        return $view;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = ValidatedData::object($data['result'] ?? []);
        $published = ($result['certificates_ready'] ?? null) === true;
        $safeResult = $this->safeResult($result);

        return DB::transaction(function () use ($operation, $data, $status, $safeResult, $published, $result): Operation {
            $operation->refresh();
            $terminal = $operation->status->terminal();
            if (! $terminal && $status === OperationStatus::Succeeded) {
                $this->tracking->complete($operation);
            }
            if (! $terminal && $published) {
                $this->tracking->publish($operation);
            }
            if (! $terminal && ($result['public_verified'] ?? false) === true) {
                $this->tracking->activatePublic($operation);
            }
            if (! $terminal && ($result['placement_cleanup'] ?? false) === true) {
                $this->tracking->cleanupPlacement($operation);
            }
            $projected = $this->direct->project($operation, $data, $status->terminal() ? $safeResult : []);

            return $projected;
        });
    }

    /** @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function safeResult(array $result): array
    {
        $safe = [];
        foreach (['ready', 'enabled', 'readable', 'detached'] as $field) {
            if (is_bool($result[$field] ?? null)) {
                $safe[$field] = $result[$field];
            }
        }
        foreach (['domain', 'timezone'] as $field) {
            if (array_key_exists($field, $result) && ($result[$field] === null || (is_string($result[$field]) && strlen($result[$field]) <= 253))) {
                $safe[$field] = $result[$field];
            }
        }

        return $safe;
    }
}
