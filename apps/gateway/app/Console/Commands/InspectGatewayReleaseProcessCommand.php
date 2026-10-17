<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseExecutionOwner;
use App\Infrastructure\GatewayReleases\GatewayReleaseProcessDeclaration;

final class InspectGatewayReleaseProcessCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:inspect-process {commit : Full target SHA} {process : Node Process ID for operator review}';

    #[\Override]
    protected $description = 'Read exact Process identity for review; never authorize an exclusion or change a hold.';

    public function handle(GatewayReleaseExecutionOwner $owner, GatewayReleaseProcessDeclaration $declaration): int
    {
        return $this->report(function () use ($owner, $declaration): array {
            $sha = $this->stringArgument('commit');
            $owner->assertAllowed($sha);
            $id = filter_var($this->stringArgument('process'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new GatewayReleaseException('drain', 'gateway.release_not_drained', 'Process must be a positive integer.', 422);
            }

            return ['observation' => $declaration->observe($sha, $id)];
        });
    }
}
