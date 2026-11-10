<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;
use Illuminate\Database\Eloquent\Builder;

/** A Project's active container default with a seed snapshot, shared by discovery and automatic admission. */
final readonly class ReadyContainerDefault
{
    /** @return Builder<Instance> */
    public static function query(): Builder
    {
        return Instance::query()->where('name', DefaultInstanceName::Name)->where('status', InstanceState::Active)
            ->whereHas('runtime', static fn (Builder $runtime): Builder => $runtime->whereNotNull('seed_commit'));
    }
}
