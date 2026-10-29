<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Fleet\AdmitFleetRolloutNodeAction;
use App\Actions\Fleet\FleetRolloutPlanAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Console\Command;

/** Records one ordered visit. Swamp runs it and records the rollout result. */
final class FleetConvergeCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:fleet-converge';

    #[\Override]
    protected $description = 'Record the next fleet rollout Operation for Swamp.';

    public function handle(FleetRolloutPlanAction $plan, AdmitFleetRolloutNodeAction $admit): int
    {
        $state = $plan->execute();
        foreach ($state['targets'] ?? [] as $target) {
            if (! $target['window'] || $target['running']) {
                continue;
            }
            try {
                $state['operation_id'] = $admit->execute(Node::query()->findOrFail($target['node_id']), ValidatedData::integer($state['rollout'] ?? null))->id;
            } catch (ResourceOperationException $error) {
                if (! in_array($error->errorCode, ['operation.target_busy', 'fleet.rollout_busy'], true)) {
                    throw $error;
                }
            }
            break;
        }
        $this->line(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
