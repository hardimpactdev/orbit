<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Loggable;
use App\Models\Node;
use Orbit\Core\Security\SecretSummaryRedactor;
use Spatie\Activitylog\Facades\LogBatch;

/**
 * Gateway activity persistence chokepoint. Every activity entry flows through
 * log(), so secret-shaped keys and values are redacted here before write —
 * callers still must not deliberately log secrets, but this boundary makes the
 * no-secrets activity contract true for every path.
 */
final readonly class ActivityLogger
{
    public function __construct(
        private ActivityLogCorrelation $correlation,
        private SecretSummaryRedactor $secretSummaryRedactor = new SecretSummaryRedactor,
    ) {}

    /**
     * @param  array<string, mixed>  $extraProperties
     */
    public function log(Loggable $loggable, string $channel, ?Node $causer, array $extraProperties = []): void
    {
        $uuid = $this->correlation->current();

        if ($uuid !== null && ! LogBatch::isOpen()) {
            LogBatch::setBatch($uuid);
        }

        /** @var array<string, mixed> $properties */
        $properties = $this->secretSummaryRedactor->redactArray(array_merge(
            ['type' => $loggable->effect()->value],
            $loggable->properties(),
            $extraProperties,
        ));
        $description = $this->secretSummaryRedactor->redactString(
            $loggable->description() ?? $loggable->type(),
        );

        $activity = activity($channel)
            ->event($loggable->type())
            ->withProperties($properties);

        if ($causer !== null) {
            $activity = $activity->causedBy($causer);
        }

        $subject = $loggable->subject();
        if ($subject !== null) {
            $activity = $activity->performedOn($subject);
        }

        $activity->log($description);

        if ($uuid !== null && LogBatch::isOpen()) {
            LogBatch::endBatch();
        }
    }
}
