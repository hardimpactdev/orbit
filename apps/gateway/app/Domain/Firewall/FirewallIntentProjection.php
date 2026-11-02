<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Data\Firewall\FirewallRuleData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\FirewallRule;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Bounded intent and domain projection only. Swamp executes the Node action. */
final readonly class FirewallIntentProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private RecordEventBroadcaster $broadcaster) {}

    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['firewall'], 'execution_secrets' => []];
    }

    /** @param array<string, mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['backend', 'error_code']));

        return DB::transaction(function () use ($operation, $data, $status, $result): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = $this->direct->project($operation, $data, $result);
            if (! $terminal && $status->terminal()) {
                $rule = FirewallRule::query()->whereKey($operation->input['rule_id'])->firstOrFail();
                if ($status === OperationStatus::Succeeded && $operation->kind === 'firewall.remove') {
                    $rule->delete();
                    $this->broadcaster->broadcast(RecordEventType::FirewallDeleted, $rule->id, ['id' => $rule->id, 'port' => $rule->port, 'protocol' => $rule->protocol, 'source' => $rule->source]);
                } else {
                    $rule->update(['status' => $status === OperationStatus::Succeeded ? LifecycleStatus::Active : LifecycleStatus::Failed,
                        'failed_step' => null, 'error_code' => $projected->error_code]);
                    if ($status === OperationStatus::Succeeded) {
                        $this->broadcaster->broadcast(RecordEventType::FirewallCreated, $rule->id,
                            FirewallRuleData::fromModel($rule->load('node'), FirewallBackendStatus::Active)->toArray());
                    }
                }
            }

            return $projected;
        });
    }
}
