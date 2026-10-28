<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Data\Firewall\FirewallRuleData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\FirewallRule;
use App\Models\Operation;

/** Projects the proven Node result. Contains no execution logic. */
final readonly class FirewallOperationCompletion implements OperationCompletion
{
    public function __construct(private RecordEventBroadcaster $broadcaster) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $rule = FirewallRule::query()->whereKey($operation->input['rule_id'])->firstOrFail();
        if ($outcome->status !== OperationStatus::Succeeded) {
            $rule->update(['status' => LifecycleStatus::Failed, 'error_code' => $outcome->errorCode,
                'failed_step' => $operation->steps()->where('status', 'failed')->value('name')]);

            return;
        }
        if ($operation->kind === 'firewall.remove') {
            $rule->delete();
            $this->broadcaster->broadcast(RecordEventType::FirewallDeleted, $rule->id, ['id' => $rule->id, 'name' => $rule->name]);

            return;
        }
        $rule->update(['status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
        if ($operation->input['created'] ?? false) {
            $this->broadcaster->broadcast(RecordEventType::FirewallCreated, $rule->id,
                FirewallRuleData::fromModel($rule->load('node'), FirewallBackendStatus::Active)->toArray());
        }
    }
}
