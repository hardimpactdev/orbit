<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\FinalizeGatewayReleaseAction;
use App\Domain\GatewayReleases\GatewayReleaseException;

final class FinalizeGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:finalize {commit : Full staged SHA} {record : Original staged release record ID}';

    #[\Override]
    protected $description = 'Verify protected recovery and finalize one staged release without migrating again.';

    public function handle(FinalizeGatewayReleaseAction $action): int
    {
        return $this->report(function () use ($action): array {
            $record = filter_var($this->stringArgument('record'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($record === false) {
                throw new GatewayReleaseException('finalize', 'gateway.release_finalize_invalid', 'Record must be a positive integer.', 422);
            }

            return $action->execute($this->stringArgument('commit'), $record)->toArray();
        });
    }
}
