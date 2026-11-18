<?php

declare(strict_types=1);

namespace App\Spike\DurableFirewall;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

/**
 * SPIKE: firewall:allow composed from typed primitives that run on the Node through orbit-executor.
 *
 * ufw.read → plan (pure PHP, the same UfwPlan the Ansible path uses) → one ufw.ensure per planned change → check.
 * The Node never receives a command line: each change is a typed rule that the executor validates and builds itself.
 */
#[Type('orbit.spike.firewall-allow-executor')]
final class FirewallAllowExecutorWorkflow extends Workflow
{
    /** @param array{ssh: array, rule: array, ensure: string} $intent */
    public function handle(array $intent, int $nodeId, int $slow = 0): array
    {
        $id = $this->workflowId();
        $read = self::activity(ExecutorPrimitive::class, $id, 'read-1', $nodeId, 'ufw.read');
        try {
            $changes = UfwPlan::changes($intent, $read['stdout']);
        } catch (UfwRefusal $refusal) {
            return ['ok' => false, 'error_code' => $refusal->getMessage(), 'failed_step' => 'plan'];
        }
        $final = $read;
        $timings = ['read' => $read['result_ms']];
        foreach ($changes as $index => $change) {
            if ($slow > 0 && $index === 0) {
                // Spike only: a durable timer between steps, so a crash can land after the read and before the change.
                self::timer($slow);
            }
            $final = self::activity(ExecutorPrimitive::class, $id, "ensure-{$index}", $nodeId, 'ufw.ensure', $change);
            $timings["ensure-{$index}"] = $final['result_ms'].($final['repeat'] ? ' repeat' : '');
        }
        if ($changes !== []) {
            $final = self::activity(ExecutorPrimitive::class, $id, 'read-2', $nodeId, 'ufw.read');
            $timings['read-2'] = $final['result_ms'].($final['repeat'] ? ' repeat' : '');
        }
        try {
            $result = UfwPlan::check($intent, $final['stdout'], count($changes));
        } catch (UfwRefusal $refusal) {
            return ['ok' => false, 'error_code' => $refusal->getMessage(), 'failed_step' => 'check', 'timings' => $timings];
        }

        return ['ok' => true, 'result' => $result, 'changes' => count($changes), 'timings' => $timings];
    }
}
