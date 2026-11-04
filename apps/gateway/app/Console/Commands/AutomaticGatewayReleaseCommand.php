<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\RunAutomaticGatewayReleaseAction;

/**
 * One tick of automatic Gateway releases, which the Gateway scheduler runs every minute.
 * It prints the tick as one JSON object. A pause for a manual deploy, or a run that did not start, exits 1; a quiet
 * skip, such as disabled, busy, up to date, or a GitHub error, exits 0.
 */
final class AutomaticGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:auto';

    #[\Override]
    protected $description = 'Release the newest green commit when automatic Gateway releases are enabled and not paused.';

    public function handle(RunAutomaticGatewayReleaseAction $action): int
    {
        $tick = $action->execute();
        $this->emit($tick);

        return in_array($tick['result'], ['failed', 'paused'], true) && $tick['record'] !== null ? self::FAILURE : self::SUCCESS;
    }
}
