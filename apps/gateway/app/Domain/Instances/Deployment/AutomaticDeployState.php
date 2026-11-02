<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Models\Instance;
use Illuminate\Support\Facades\DB;

/**
 * What the automatic deployment tick last saw for one Instance, kept in `instances.auto_deploy_state`: the last
 * result and since when it holds, the public policy projection, pause,
 * and durable rollback blocks. The tick writes it only when something in it changed.
 */
final class AutomaticDeployState
{
    /** The newest commits that rollbacks moved away from, which stay blocked. */
    private const int BlockedLimit = 20;

    /** @var array<string, mixed> */
    private array $original;

    /** @param array<string, mixed> $state */
    private function __construct(private array $state)
    {
        $this->original = $state;
    }

    public static function of(Instance $instance): self
    {
        return new self(is_array($instance->auto_deploy_state) ? $instance->auto_deploy_state : []);
    }

    /** @return array{reason: string, deployment_id: int, commit: string|null, paused_at: string}|null */
    public function pause(): ?array
    {
        $pause = $this->state['pause'] ?? null;

        return is_array($pause) && is_string($pause['reason'] ?? null) && is_int($pause['deployment_id'] ?? null) && is_string($pause['paused_at'] ?? null)
            ? ['reason' => $pause['reason'], 'deployment_id' => $pause['deployment_id'], 'commit' => is_string($pause['commit'] ?? null) ? $pause['commit'] : null, 'paused_at' => $pause['paused_at']]
            : null;
    }

    /** Keeps a commit from deploying automatically, such as the commit a rollback moved away from. */
    public function block(string $commit): void
    {
        $this->state['blocked'] = array_slice(array_values(array_unique([...$this->blocked(), $commit])), -self::BlockedLimit);
    }

    public function operatorControl(): void
    {
        $this->state['operator_version'] = (is_int($this->state['operator_version'] ?? null) ? $this->state['operator_version'] : 0) + 1;
    }

    /** @return list<string> */
    public function blocked(): array
    {
        $blocked = $this->state['blocked'] ?? null;

        return is_array($blocked) ? array_values(array_filter($blocked, is_string(...))) : [];
    }

    /** Writes the state when it changed. It touches no other column of the Instance and no timestamp. */
    public function save(Instance $instance): void
    {
        if ($this->state === $this->original) {
            return;
        }

        DB::table('instances')->where('id', $instance->id)->update(['auto_deploy_state' => json_encode($this->state, JSON_THROW_ON_ERROR)]);
        $instance->setRawAttributes([...$instance->getAttributes(), 'auto_deploy_state' => json_encode($this->state, JSON_THROW_ON_ERROR)], sync: true);
        $this->original = $this->state;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->state;
    }
}
