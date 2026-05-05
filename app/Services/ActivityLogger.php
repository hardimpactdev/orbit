<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Loggable;
use App\Models\Node;
use Spatie\Activitylog\Facades\LogBatch;

final readonly class ActivityLogger
{
    public function __construct(private ActivityLogCorrelation $correlation) {}

    /**
     * @param  array<string, mixed>  $extraProperties
     */
    public function log(Loggable $loggable, string $channel, ?Node $causer, array $extraProperties = []): void
    {
        $uuid = $this->correlation->current();

        if ($uuid !== null && ! LogBatch::isOpen()) {
            LogBatch::setBatch($uuid);
        }

        $activity = activity($channel)
            ->event($loggable->activityLogAction())
            ->withProperties(array_merge(
                ['type' => $loggable->activityLogType()->value],
                $loggable->activityLogProperties(),
                $extraProperties,
            ));

        if ($causer !== null) {
            $activity = $activity->causedBy($causer);
        }

        $subject = $loggable->activityLogSubject();
        if ($subject !== null) {
            $activity = $activity->performedOn($subject);
        }

        $activity->log($loggable->activityLogDescription() ?? $loggable->activityLogAction());

        if ($uuid !== null && LogBatch::isOpen()) {
            LogBatch::endBatch();
        }
    }
}
