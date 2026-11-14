<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Models\Instance;
use App\Models\Operation;

/**
 * The Gateway's answer for one Instance of a T3 restart for the machine name (docs/cli/conn/environment-restart.mdx): `restarting` with
 * the admitted Operation, `current`, `busy` or `skipped` with no run, or `failed` when the admission failed. The Operation's own
 * result says `restarted`, `skipped` or `failed`.
 */
final readonly class ConnEnvironmentRestartDecision
{
    private function __construct(public int $instanceId, public string $instance, public string $answer, public ?string $reason, public ?int $operationId) {}

    public static function restarting(Instance $instance, Operation $operation): self
    {
        return new self($instance->id, $instance->name, 'restarting', null, $operation->id);
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

    public static function failed(Instance $instance, string $code, ?Operation $operation): self
    {
        return new self($instance->id, $instance->name, 'failed', $code, $operation?->id);
    }

    /** @return array{instance_id: int, instance: string, answer: string, reason: string|null, operation_id: int|null} */
    public function toArray(): array
    {
        return ['instance_id' => $this->instanceId, 'instance' => $this->instance, 'answer' => $this->answer, 'reason' => $this->reason,
            'operation_id' => $this->operationId];
    }
}
