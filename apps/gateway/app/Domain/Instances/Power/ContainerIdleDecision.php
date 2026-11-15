<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Models\Instance;

/**
 * One container Instance in one idle sweep: `sleep` (with its Operation, none in a dry run) or `skip` with the reason, and the evidence
 * the decision used. A time is a Unix time, or null when the sweep did not read it or found none. `t3` is the agent read: null when the
 * sweep did not read it. `t3Server` is false for an Instance without a T3 server, which has no agent to read.
 */
final readonly class ContainerIdleDecision
{
    public function __construct(
        public int $instanceId,
        public string $name,
        public string $decision,
        public string $reason,
        public ?int $operationId = null,
        public ?int $watchStartedAt = null,
        public ?int $lastHttpAt = null,
        public ?AgentActivity $t3 = null,
        public ?int $lastWakeAt = null,
        public ?int $lastDeployAt = null,
        public bool $t3Server = true,
    ) {}

    public static function skip(Instance $instance, string $reason): self
    {
        return new self($instance->id, $instance->name, 'skip', $reason);
    }

    public function with(string $decision, string $reason, ?int $operationId = null): self
    {
        return new self($this->instanceId, $this->name, $decision, $reason, $operationId, $this->watchStartedAt, $this->lastHttpAt, $this->t3,
            $this->lastWakeAt, $this->lastDeployAt, $this->t3Server);
    }
}
