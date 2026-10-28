<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Integrations\IntegrationPlan;
use App\Domain\Integrations\IntegrationSecretSource;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class StartIntegrationOperationAction
{
    public function __construct(private StartOperationAction $operations, private IntegrationSecretSource $secrets) {}

    /** @param array<string, mixed> $input */
    public function prepare(Operation $operation, array $input, IntegrationPlan $plan): Operation
    {
        if (isset($operation->state['integration_intent'])) { return $operation; }
        $operation->state = [...($operation->state ?? []), 'integration_intent' => [...$input, 'effects' => $plan->effects, 'flow' => $plan->flow]];
        $operation->save();
        $this->secrets->store($operation, $plan->protected);
        return $operation;
    }

    /** @param array<string, mixed> $input */
    public function execute(string $kind, string $target, int $targetId, int $nodeId, array $input, IntegrationPlan $plan, string $trigger = 'operator'): Operation
    {
        return DB::transaction(function () use ($kind, $target, $targetId, $nodeId, $input, $plan, $trigger): Operation {
            $operation = $this->operations->execute($kind, $target, $targetId, $nodeId,
                [...$input, 'effects' => $plan->effects, 'flow' => $plan->flow], $trigger, null);
            if ($operation->wasRecentlyCreated) {
                $this->secrets->store($operation, $plan->protected);
            }

            return $operation;
        });
    }
}
