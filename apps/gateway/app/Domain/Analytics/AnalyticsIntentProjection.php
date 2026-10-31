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
            'tracking' => $operation->state['tracking'] ?? null,
            ...(! $operation->status->terminal() && isset($operation->state['tracking']) ? $this->ownView($operation) : [])];
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
        if (in_array($nodeId, $dnsIds, true)) {
            if (! PrivateDnsPublicationNodes::query()->whereKey($nodeId)->exists()) {
                throw new ResourceOperationException('analytics.tracking_changed', 'The admitted DNS owner changed.', 409);
            }
            $dns = app(DevelopmentDnsConfigRenderer::class);
            $catalog = json_encode(['requesters' => $dns->registeredRequesters(), ...$dns->catalog()->toPublished()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            $view['dns_publication'] = ['node_id' => $nodeId, 'records' => $dns->render(), 'catalog' => $catalog, 'digest' => hash('sha256', $catalog)];
        }

        return $view;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = ValidatedData::object($data['result'] ?? []);
        $requests = $result['certificate_requests'] ?? null;
        $published = $result === ['certificates_ready' => true];
        $site = ValidatedData::object($operation->input['site'] ?? []);
        $expected = ['ready' => true, 'enabled' => $operation->input['enabled'], 'domain' => $site['domain'] ?? null,
            'timezone' => $site['timezone'] ?? null, ...($operation->input['enabled'] ? ['readable' => true] : ['detached' => true])];
        $ordered = $result;
        ksort($ordered);
        ksort($expected);
        if (($status === OperationStatus::Succeeded && $ordered !== $expected)
            || ($status === OperationStatus::Failed && $result !== ['error_code' => ($data['error_code'] ?? null)])
            || ($status === OperationStatus::Cancelled && $result !== [])
            || ($status === OperationStatus::Running && $result !== [] && ($requests === null || array_keys($result) !== ['certificate_requests']) && ! $published)
            || ($status !== OperationStatus::Failed && ($data['error_code'] ?? null) !== null)
            || ($status === OperationStatus::Failed && ($data['error_code'] ?? null) !== 'analytics.change_failed')
            || ($status !== OperationStatus::Running && ($requests !== null || $published))) {
            throw new ResourceOperationException('operation.result_invalid', 'Analytics needs bounded observations matching its admitted site.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $status, $result, $requests, $published): Operation {
            $operation->refresh();
            $terminal = $operation->status->terminal();
            if (! $terminal && $status === OperationStatus::Succeeded) {
                $this->tracking->complete($operation);
            }
            if (! $terminal && $requests !== null) {
                $this->requests($operation, $requests);
            }
            if (! $terminal && $published) {
                $this->tracking->publish($operation);
            }
            $projected = $this->direct->project($operation, $data, $status->terminal() ? $result : [], 'analytics');
            if (! $terminal && $status === OperationStatus::Running && ! isset($projected->state['tracking'])) {
                $plan = $this->tracking->materialize($projected);
                $projected->update(['state' => [...($projected->state ?? []), 'tracking' => $plan]]);
            }

            return $projected;
        });
    }

    private function requests(Operation $operation, mixed $requests): void
    {
        $this->tracking->assertCurrent($operation);
        if (! is_array($requests) || ! array_is_list($requests) || count($requests) > 20) {
            throw new ResourceOperationException('certificate.request_invalid', 'Only admitted tracking signing requests are accepted.', 422);
        }
        $stored = [];
        $prior = ValidatedData::object($operation->state['certificate_requests'] ?? []);
        foreach ($requests as $request) {
            if (! is_array($request) || array_diff(array_keys($request), ['node_id', 'scope', 'request_base64']) !== []) {
                throw new ResourceOperationException('certificate.request_invalid', 'The tracking signing request is invalid.', 422);
            }
            $leaf = collect($this->tracking->plan($operation)['certificates'])->first(static fn (array $leaf): bool => $leaf['node_id'] === ($request['node_id'] ?? null) && $leaf['scope'] === ($request['scope'] ?? null));
            $encoded = $request['request_base64'] ?? null;
            $pem = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (! is_array($leaf) || ! is_string($encoded) || ! is_string($pem) || strlen($encoded) > 32768
                || ! str_starts_with($pem, '-----BEGIN CERTIFICATE REQUEST-----') || @openssl_csr_get_public_key($pem) === false) {
                throw new ResourceOperationException('certificate.request_invalid', 'The public request does not match an admitted tracking hostname.', 422);
            }
            $key = $leaf['node_id'].':'.$leaf['scope'];
            if (isset($stored[$key]) || (isset($prior[$key]) && $prior[$key] !== $encoded)) {
                throw new ResourceOperationException('certificate.request_invalid', 'An admitted tracking signing request changed.', 409);
            }
            $stored[$key] = $encoded;
        }
        $operation->update(['state' => [...($operation->state ?? []), 'certificate_requests' => $stored]]);
    }
}
