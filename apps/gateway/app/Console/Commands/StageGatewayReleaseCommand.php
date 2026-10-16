<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\DeployGatewayReleaseAction;

final class StageGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:stage {commit : Full SHA to stage under an operator-maintained drain}';

    #[\Override]
    protected $description = 'Snapshot, migrate and switch a drained Gateway, leaving all consumers held.';

    public function handle(DeployGatewayReleaseAction $action): int
    {
        return $this->report(fn (): array => $action->stage($this->stringArgument('commit'))->toArray());
    }
}
