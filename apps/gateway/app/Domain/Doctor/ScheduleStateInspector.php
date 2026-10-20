<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Infrastructure\Doctor\NativeScheduleStateInspector;
use App\Models\Node;
use App\Models\Schedule;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeScheduleStateInspector::class)]
interface ScheduleStateInspector
{
    public function inspect(Schedule $schedule): ScheduleInspectionData;

    /**
     * @param  list<string>  $knownIds
     * @return list<string>
     */
    public function orphanIds(Node $node, array $knownIds): array;
}
