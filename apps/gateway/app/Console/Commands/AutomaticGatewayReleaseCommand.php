<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\RunAutomaticGatewayReleaseAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;

/**
 * One tick of automatic Gateway releases, started every minute by `orbit-gateway-release.timer`.
 * It prints the tick as one JSON object. Only a release that failed exits 1; a quiet skip, such as
 * disabled, paused, busy, up to date, or a GitHub error, exits 0.
 */
final class AutomaticGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:auto';

    #[\Override]
    protected $description = 'Release the newest green commit when automatic Gateway releases are enabled and not paused.';

    public function handle(RunAutomaticGatewayReleaseAction $action): int
    {
        $until = CarbonImmutable::now()->addMinutes(RunAutomaticGatewayReleaseAction::PublicationWaitMinutes);
        do {
            $tick = $action->execute();
            $this->emit($tick);
            if ($tick['result'] !== 'waiting_for_agent_delivery' || CarbonImmutable::now()->gte($until)) {
                break;
            }
            Sleep::for(RunAutomaticGatewayReleaseAction::PublicationCheckSeconds)->seconds();
        } while (true);

        return in_array($tick['result'], ['failed', 'paused'], true) && $tick['record'] !== null ? self::FAILURE : self::SUCCESS;
    }
}
