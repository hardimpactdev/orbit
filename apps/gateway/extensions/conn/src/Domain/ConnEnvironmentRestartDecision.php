<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Data\Operations\OperationData;
use App\Models\Instance;
use App\Models\Operation;

/**
 * The Gateway's answer for one Instance of a T3 restart for the machine name (docs/cli/conn/environment-restart.mdx): `restarting` with
 * the admitted Operation, or `current`, `busy` or `skipped` with no run. The Operation's own result says `restarted` or `failed`.
 */
final readonly class ConnEnvironmentRestartDecision
{
    private function __construct(public int $instanceId, public string $instance, public string $answer, public ?string $reason, public ?Operation $operation) {}

    public static function restarting(Instance $instance, Operation $operation): self
    {
        return new self($instance->id, $instance->name, 'restarting', null, $operation);
    }

    public static function current(Instance $instance): self
    {
        return new self($instance->id, $instance->name, 'current', null, null);
    }

    public static function busy(Instance $instance, string $reason): self
    {
        return new self($instance->id, $instance->name, 'busy', $reason, null);
    }

    public static function skipped(Instance $instance, string $reason): self
    {
        return new self($instance->id, $instance->name, 'skipped', $reason, null);
    }

    /** @return array{instance_id: int, instance: string, answer: string, reason: string|null, operation: array<string, mixed>|null} */
    public function toArray(): array
    {
        return ['instance_id' => $this->instanceId, 'instance' => $this->instance, 'answer' => $this->answer, 'reason' => $this->reason,
            'operation' => $this->operation === null ? null : OperationData::fromModel($this->operation)->toArray()];
    }
}
