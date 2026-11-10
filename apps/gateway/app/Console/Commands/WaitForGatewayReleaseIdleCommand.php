<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\WaitForGatewayReleaseIdleAction;
use App\Domain\GatewayReleases\GatewayReleaseException;
use Illuminate\Console\Command;

/** A bounded DB read for the release run; it never changes an Operation. */
final class WaitForGatewayReleaseIdleCommand extends Command
{
    #[\Override]
    protected $signature = 'gateway:release:wait-idle';

    #[\Override]
    protected $description = 'Wait up to ten minutes for active Operations before loading Swamp.';

    public function handle(WaitForGatewayReleaseIdleAction $idle): int
    {
        try {
            $idle->execute();
        } catch (GatewayReleaseException $exception) {
            $this->error($exception->errorCode.': '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('No release-blocking Operations are active.');

        return self::SUCCESS;
    }
}
