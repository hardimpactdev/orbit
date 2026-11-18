<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\StepSpec;
use App\Models\Operation;
use App\Support\ValidatedData;
use LogicException;

/**
 * `firewall:allow`, `firewall:deny` and `firewall:remove` on the state machine (ADR 0271; docs/cli/firewall/allow.mmd). One
 * step: the desired-state primitive `ufw.ensure_rule` on the rule's Node, from the intent the Gateway admitted. The Node reads
 * its own table, places the rule, applies, checks and answers how many changes it made. The end records through the kind's
 * own projection, as a Swamp run's end did.
 */
final readonly class FirewallEngineFlow implements EngineDefinition
{
    public function __construct(private FirewallIntentProjection $projection) {}

    public function steps(Operation $operation): array
    {
        $node = self::node($operation);

        // The target is the Node itself: its firewall changes run one at a time.
        return [new StepSpec('ensure-0', $node, 'ufw.ensure_rule', (string) $node, budgetMs: 120_000, resendMs: 10_000)];
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        $intent = ValidatedData::object($operation->input['firewall'] ?? []);
        $rule = ValidatedData::object($intent['rule'] ?? []);

        return ['action' => ValidatedData::string($rule['action']), 'port' => ValidatedData::string($rule['port']),
            'protocol' => ValidatedData::string($rule['protocol']), 'source' => ValidatedData::string($rule['source']),
            'comment' => ValidatedData::string($rule['comment']), 'ensure' => ValidatedData::string($intent['ensure']),
            'retain' => ($rule['retain'] ?? false) === true];
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        throw new LogicException('The firewall flow has no Gateway step.');
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        $absent = (ValidatedData::object($operation->input['firewall'] ?? [])['ensure'] ?? null) === 'absent';
        $failed = $run->failed();
        $data = match (true) {
            $operation->cancel_requested_at !== null => ['status' => 'cancelled', 'error_code' => 'operation.cancelled', 'result' => []],
            $failed !== null => ['status' => 'failed', 'error_code' => $failed->error_code,
                'failure' => ['failed_step' => $failed->name, 'stderr_recorded' => false], 'result' => ['error_code' => $failed->error_code]],
            default => ['status' => 'succeeded', 'error_code' => null, 'result' => ['backend' => $absent ? 'absent' : 'active',
                ...($run->changes() === 0 ? ['skipped' => $absent ? 'firewall.rule_absent' : 'firewall.rule_exists'] : [])]],
        };
        $this->projection->apply($operation, [...$data, ...$run->times()]);
    }

    private static function node(Operation $operation): int
    {
        $rule = ValidatedData::object(ValidatedData::object($operation->input['firewall'] ?? [])['rule'] ?? []);

        $node = $rule['node_id'] ?? $operation->node_id;

        return is_int($node) ? $node : throw new LogicException('A firewall intent names its Node.');
    }
}
