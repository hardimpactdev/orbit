<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\AbandonGatewayReleaseAction;
use App\Domain\GatewayReleases\GatewayReleaseException;

final class AbandonGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:abandon {commit : Full staged SHA} {record : Original staged release record ID}';

    #[\Override]
    protected $description = 'Clear one staged hold only after a verified complete snapshot restore.';

    public function handle(AbandonGatewayReleaseAction $action): int
    {
        return $this->report(function () use ($action): array {
            $record = filter_var($this->stringArgument('record'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($record === false) {
                throw new GatewayReleaseException('abandon', 'gateway.release_abandon_invalid', 'Record must be a positive integer.', 422);
            }

            return $action->execute($this->stringArgument('commit'), $record);
        });
    }
}
