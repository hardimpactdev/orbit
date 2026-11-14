<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;
use Illuminate\Container\Attributes\Tag;

/** The tagged records of a container Instance's threads: the task copy's turn and the sleep admission call them. */
final readonly class InstanceThreadContributions
{
    /** @param iterable<InstanceThreadContribution> $contributions */
    public function __construct(#[Tag(InstanceThreadContribution::class)] private iterable $contributions) {}

    public function recordThread(Instance $instance, string $threadId, string $title): void
    {
        foreach ($this->contributions as $contribution) {
            $contribution->recordThread($instance, $threadId, $title);
        }
    }

    public function beforeSleep(Instance $instance): void
    {
        foreach ($this->contributions as $contribution) {
            $contribution->beforeSleep($instance);
        }
    }
}
