<?php

declare(strict_types=1);

namespace App\Spike\DurableFirewall;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

/**
 * SPIKE: firewall:allow as a durable workflow on the Gateway's own queues.
 *
 * Read (Ansible) → plan (pure PHP, deterministic, inline) → change + re-read (one Ansible run) → check (pure PHP).
 * Each activity result is stored in the workflow history, so a Horizon restart resumes after the last finished step.
 */
#[Type('orbit.spike.firewall-allow')]
final class FirewallAllowWorkflow extends Workflow
{
    /** @param array{ssh: array, rule: array, ensure: string} $intent */
    public function handle(array $intent, int $slow = 0): array
    {
        $first = self::activity(UfwPlaybook::class, $intent['ssh'], []);
        try {
            $commands = UfwPlan::plan($intent, $first);
        } catch (UfwRefusal $refusal) {
            return ['ok' => false, 'error_code' => $refusal->getMessage(), 'failed_step' => 'plan'];
        }
        if ($commands !== [] && $slow > 0) {
            // Spike only: hold the change activity open so a restart can land inside it.
            array_unshift($commands, ['sleep', (string) $slow]);
        }
        $final = $commands === [] ? $first : self::activity(UfwPlaybook::class, $intent['ssh'], $commands);
        try {
            $result = UfwPlan::check($intent, $final, count($commands));
        } catch (UfwRefusal $refusal) {
            return ['ok' => false, 'error_code' => $refusal->getMessage(), 'failed_step' => 'check'];
        }

        return ['ok' => true, 'result' => $result, 'changes' => count($commands)];
    }
}
