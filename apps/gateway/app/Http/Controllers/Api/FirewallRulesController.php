<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Firewall\ListFirewallRulesAction;
use App\Actions\Firewall\ListLiveFirewallRulesAction;
use App\Actions\Firewall\ListManagedFirewallRulesAction;
use App\Actions\Firewall\RemoveFirewallRuleAction;
use App\Actions\Firewall\StoreFirewallRuleAction;
use App\Data\Firewall\FirewallRuleData;
use App\Data\Operations\OperationData;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Firewall\RemoveFirewallRuleRequest;
use App\Http\Requests\Firewall\StoreFirewallRuleRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\FirewallRule;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Target)]
final class FirewallRulesController extends Controller
{
    public function index(
        Request $request,
        Node $node,
        ListFirewallRulesAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => $action
                ->execute($node)
                ->map(static function (FirewallRule $rule): array {
                    $data = FirewallRuleData::fromModel($rule)->toArray();
                    unset($data['backend_status']);

                    return $data;
                })
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    /** Orbit's own rules for the Node, read-only: no request adds, changes, or removes one. */
    public function managed(
        Request $request,
        Node $node,
        ListManagedFirewallRulesAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => $action->execute($node),
            'meta' => $this->meta($request),
        ]);
    }

    /** Live UFW on the Node, classified against the desired managed set and operator records. */
    public function live(
        Request $request,
        Node $node,
        ListLiveFirewallRulesAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => $action->execute($node),
            'meta' => $this->meta($request),
        ]);
    }

    #[StartsOperation('firewall.allow')]
    public function allow(StoreFirewallRuleRequest $request, Node $node, StoreFirewallRuleAction $action): JsonResponse
    {
        return $this->store($request, $node, $action);
    }

    #[StartsOperation('firewall.deny')]
    public function deny(StoreFirewallRuleRequest $request, Node $node, StoreFirewallRuleAction $action): JsonResponse
    {
        return $this->store($request, $node, $action);
    }

    private function store(StoreFirewallRuleRequest $request, Node $node, StoreFirewallRuleAction $action): JsonResponse
    {
        $caller = $request->user();
        $operation = $action->execute($node, $request->getData(), $caller instanceof Node ? $caller : null,
            $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    #[StartsOperation('firewall.remove')]
    public function destroy(RemoveFirewallRuleRequest $request, Node $node, RemoveFirewallRuleAction $action): JsonResponse
    {
        $filters = $request->filters();
        $matches = $node->firewallRules()->where('port', $filters['port'])
            ->when($filters['source'] !== null, static fn ($query) => $query->where('source', $filters['source']))
            ->when($filters['protocol'] !== null, static fn ($query) => $query->where('protocol', $filters['protocol']))
            ->orderBy('protocol')->orderBy('source')->get();
        if ($matches->isEmpty()) {
            throw new ResourceOperationException('firewall.rule_not_found', "Node [{$node->name}] has no firewall rule on port {$filters['port']} that matches.", 404);
        }
        if ($matches->count() > 1) {
            throw new ResourceOperationException('firewall.rule_ambiguous', "Several firewall rules on port {$filters['port']} match; narrow with --from or --protocol.", 409,
                details: ['matches' => $matches->map(static fn (FirewallRule $rule): array => ['port' => $rule->port, 'protocol' => $rule->protocol,
                    'source' => $rule->source, 'action' => $rule->action->value, 'label' => $rule->label])->all()]);
        }
        $firewallRule = $matches->first();
        $caller = $request->user();
        $operation = $action->execute($firewallRule, $caller instanceof Node ? $caller : null,
            $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
