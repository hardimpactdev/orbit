<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Sleep;

/** The release's pre-load DB guard, called by Swamp while the old Horizon still works. */
final readonly class WaitForGatewayReleaseIdleAction
{
    /** SQL LIKE patterns: all deploy variants and every Conn and Process kind, including future ones. */
    public const array BlockingKindPatterns = ['instance.create', 'instance.deploy%', 'conn.%', 'process.%'];

    public const int WaitSeconds = 600;

    public const int PollSeconds = 5;

    public function execute(): void
    {
        $deadline = now()->addSeconds(self::WaitSeconds);
        while (($ids = $this->blockingIds()) !== []) {
            if (now()->greaterThanOrEqualTo($deadline)) {
                throw new GatewayReleaseException('idle', 'gateway.release_busy',
                    'Active Operations after '.self::WaitSeconds.' seconds: '.implode(', ', $ids), phase: ['operation_ids' => $ids]);
            }
            Sleep::for(min(self::PollSeconds, now()->diffInSeconds($deadline, false)))->seconds();
        }
    }

    /** @return list<int> */
    private function blockingIds(): array
    {
        return ValidatedData::integerList(Operation::query()->whereIn('status', [OperationStatus::Queued, OperationStatus::Running, OperationStatus::Waiting])
            ->where(function (Builder $query): void {
                foreach (self::BlockingKindPatterns as $pattern) {
                    $query->orWhere('kind', 'like', $pattern);
                }
            })->orderBy('id')->pluck('id')->all());
    }
}
