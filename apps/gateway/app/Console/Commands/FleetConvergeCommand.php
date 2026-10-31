<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Fleet\AdmitFleetRolloutAction;
use Illuminate\Console\Command;

/** Admit one native fleet-wide run. Swamp owns per-Node order and execution. */
final class FleetConvergeCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:fleet-converge';

    #[\Override]
    protected $description = 'Record one fleet rollout Operation for Swamp.';

    public function handle(AdmitFleetRolloutAction $admit): int
    {
        $operation = $admit->execute();
        $this->line(json_encode(['operation_id' => $operation->id], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
