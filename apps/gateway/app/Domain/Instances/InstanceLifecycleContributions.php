<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use Illuminate\Container\Attributes\Tag;

/**
 * The tagged contributions to a container Instance's create and destroy flows. A clone runs T3, and only the Conn layer records a T3 server, so a
 * clone needs at least one contribution: without one it refuses with `operation.kind_unavailable`.
 */
final readonly class InstanceLifecycleContributions
{
    /** @param iterable<InstanceLifecycleContribution> $contributions */
    public function __construct(#[Tag(InstanceLifecycleContribution::class)] private iterable $contributions) {}

    public function assertCreateAvailable(): void
    {
        $present = false;
        foreach ($this->contributions as $contribution) {
            $present = true;
            $contribution->assertCreateAvailable();
        }
        if (! $present) {
            throw new ResourceOperationException('operation.kind_unavailable', 'A clone needs the Conn layer to record its T3 server.', 503);
        }
    }

    public function projectDestroy(Instance $instance): void
    {
        foreach ($this->contributions as $contribution) {
            $contribution->projectDestroy($instance);
        }
    }

    /** @param array<string, mixed> $server */
    public function projectCreate(Instance $instance, array $server): void
    {
        foreach ($this->contributions as $contribution) {
            $contribution->projectCreate($instance, $server);
        }
    }
}
