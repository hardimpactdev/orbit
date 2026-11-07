<?php

declare(strict_types=1);

namespace App\Actions\Firewall;

use App\Domain\Firewall\FirewallInspectionInput;
use App\Domain\Firewall\LiveFirewallBackendStatus;
use App\Domain\Firewall\LiveFirewallSnapshot;
use App\Domain\NodeFacts\NodeFactKind;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeFact;
use UnexpectedValueException;

/**
 * The live UFW rules of a Node, from the `ufw` fact its agent reported, compared with what Orbit intends there. It
 * never reaches the Node. Until the agent reports UFW, or when a rule cannot be read, every intended rule is
 * unverifiable.
 */
final readonly class ListLiveFirewallRulesAction
{
    public function __construct(private FirewallInspectionInput $desired) {}

    /** @return array{backend_status:string,observed_at:?string,live:list<array<string,mixed>>,missing:list<array<string,mixed>>} */
    public function execute(Node $node): array
    {
        if ($node->platform !== 'linux') {
            return ['backend_status' => 'unverifiable', 'observed_at' => null, 'live' => [], 'missing' => []];
        }
        $expected = $this->desired->expected($node);
        $fact = NodeFact::query()->where('node_id', $node->id)->where('kind', NodeFactKind::Ufw)->first();
        if ($fact !== null && ($fact->data['installed'] ?? null) === false) {
            return ['backend_status' => LiveFirewallBackendStatus::Absent->value, 'observed_at' => $fact->observed_at->toIso8601ZuluString(), 'live' => [], 'missing' => []];
        }
        try {
            $snapshot = $fact === null ? null : LiveFirewallSnapshot::compare($fact->data, $expected);
        } catch (UnexpectedValueException) {
            $snapshot = null;
        }
        if ($snapshot === null) {
            return ['backend_status' => 'unverifiable', 'observed_at' => $fact?->observed_at->toIso8601ZuluString(), 'live' => array_map(static fn (array $rule): array => [
                'name' => $rule['name'], 'comment' => $rule['shape']['comment'], 'action' => $rule['shape']['action'],
                'source' => $rule['shape']['source'], 'destination' => $rule['shape']['destination'], 'port' => $rule['shape']['port'],
                'protocol' => $rule['shape']['protocol'], 'interface' => $rule['shape']['in_interface'], 'family' => $rule['shape']['family'],
                'match' => 'unverifiable',
            ], $expected), 'missing' => []];
        }

        return ['backend_status' => $snapshot['backend'], 'observed_at' => $fact?->observed_at->toIso8601ZuluString(), 'live' => $snapshot['live'], 'missing' => $snapshot['missing']];
    }

    /**
     * Whether each operator rule has all its lines in the UFW report: `exact`, `drift`, or `missing` when a line is absent.
     * Only an active firewall can be compared; call it when `execute()` reported `active`.
     *
     * @param  iterable<FirewallRule>  $rules
     * @return array<int, string> the match by rule ID
     */
    public function matchRules(Node $node, iterable $rules): array
    {
        $fact = NodeFact::query()->where('node_id', $node->id)->where('kind', NodeFactKind::Ufw)->first();
        $matches = [];

        foreach ($rules as $rule) {
            try {
                if ($fact === null) {
                    $matches[$rule->id] = 'unverifiable';

                    continue;
                }
                $matches[$rule->id] = LiveFirewallSnapshot::comparison($fact->data, $this->desired->forRule($rule));
            } catch (UnexpectedValueException) {
                $matches[$rule->id] = 'unverifiable';
            }
        }

        return $matches;
    }
}
