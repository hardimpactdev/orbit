<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Models\Instance;

/** One container Instance in one idle sweep: `sleep` (with its Operation, none in a dry run) or `skip` with the reason. */
final readonly class ContainerIdleDecision
{
    private function __construct(public int $instanceId, public string $name, public string $decision, public string $reason, public ?int $operationId) {}

    public static function sleep(Instance $instance, ?int $operationId): self
    {
        return new self($instance->id, $instance->name, 'sleep', 'idle', $operationId);
    }

    public static function skip(Instance $instance, string $reason): self
    {
        return new self($instance->id, $instance->name, 'skip', $reason, null);
    }
}
