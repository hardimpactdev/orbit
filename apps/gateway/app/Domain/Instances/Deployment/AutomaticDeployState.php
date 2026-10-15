<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Models\Instance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the automatic deployment tick last saw for one Instance, kept in `instances.auto_deploy_state`: the last
 * result and since when it holds, the gate answer for a branch head, the stall of an undeployed commit, the pause,
 * and which alerts went out. The tick writes it only when something in it changed.
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

    /** Records the tick's result. `since` moves only when the result or its commit changes. */
    public function result(AutomaticDeployOutcome $outcome): bool
    {
        $changed = ($this->state['result'] ?? null) !== $outcome->result->value || ($this->state['commit'] ?? null) !== $outcome->commit;
        $this->state = [
            ...$this->state,
            'result' => $outcome->result->value,
            'commit' => $outcome->commit,
            'operation_id' => $outcome->operationId,
            'error_code' => $outcome->errorCode,
            'message' => $outcome->message,
            'since' => $changed ? Carbon::now()->toIso8601String() : ($this->state['since'] ?? Carbon::now()->toIso8601String()),
        ];

        return $changed;
    }

    public function head(?string $head): void
    {
        $this->state['head'] = $head;
    }

    /**
     * The gate answer the tick keeps for this head, deployed commit, and list of commits that may not deploy, so it
     * reads no check run again until one of them changes or the answer expires.
     *
     * @param  list<string>  $failed
     */
    public function settledGate(string $head, string $deployed, array $failed): ?AutomaticDeployResult
    {
        $gate = $this->state['gate'] ?? null;

        if (! is_array($gate) || ! is_string($gate['result'] ?? null)) {
            return null;
        }

        foreach (self::gateKey($head, $deployed, $failed) as $key => $value) {
            if (($gate[$key] ?? null) !== $value) {
                return null;
            }
        }

        $until = $gate['until'] ?? null;

        if (is_string($until) && Carbon::parse($until)->lte(Carbon::now())) {
            return null;
        }

        return AutomaticDeployResult::tryFrom($gate['result']);
    }

    /**
     * Keeps the gate answer. With `$minutes`, it expires then, so a check that runs again on the same head is read.
     *
     * @param  list<string>  $failed
     */
    public function settleGate(string $head, string $deployed, array $failed, AutomaticDeployResult $result, ?int $minutes = null): void
    {
        $this->state['gate'] = [
            ...self::gateKey($head, $deployed, $failed),
            'result' => $result->value,
            'until' => $minutes === null ? null : Carbon::now()->addMinutes($minutes)->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $failed
     * @return array{head: string, deployed: string, failed: string}
     */
    private static function gateKey(string $head, string $deployed, array $failed): array
    {
        sort($failed);

        return ['head' => $head, 'deployed' => $deployed, 'failed' => sha1(implode(',', $failed))];
    }

    public function forgetGate(): void
    {
        $this->state['gate'] = null;
    }

    /**
     * Notes that `$sha` waits to deploy and returns true once when it has waited `$minutes` minutes without an alert.
     */
    public function stalled(string $sha, int $minutes): bool
    {
        $stall = $this->state['stall'] ?? null;

        if (! is_array($stall) || ($stall['sha'] ?? null) !== $sha || ! is_string($stall['since'] ?? null)) {
            $this->state['stall'] = ['sha' => $sha, 'since' => Carbon::now()->toIso8601String(), 'alerted' => false];

            return false;
        }

        if (($stall['alerted'] ?? false) === true || Carbon::parse($stall['since'])->gt(Carbon::now()->subMinutes($minutes))) {
            return false;
        }

        $this->state['stall'] = [...$stall, 'alerted' => true];

        return true;
    }

    /** @return array{sha: string, since: string, alerted: bool}|null */
    public function stall(): ?array
    {
        $stall = $this->state['stall'] ?? null;

        return is_array($stall) && is_string($stall['sha'] ?? null) && is_string($stall['since'] ?? null)
            ? ['sha' => $stall['sha'], 'since' => $stall['since'], 'alerted' => ($stall['alerted'] ?? false) === true]
            : null;
    }

    public function clearStall(): void
    {
        $this->state['stall'] = null;
    }

    /** @return array{reason: string, deployment_id: int, commit: string|null, paused_at: string}|null */
    public function pause(): ?array
    {
        $pause = $this->state['pause'] ?? null;

        return is_array($pause) && is_string($pause['reason'] ?? null) && is_int($pause['deployment_id'] ?? null) && is_string($pause['paused_at'] ?? null)
            ? ['reason' => $pause['reason'], 'deployment_id' => $pause['deployment_id'], 'commit' => is_string($pause['commit'] ?? null) ? $pause['commit'] : null, 'paused_at' => $pause['paused_at']]
            : null;
    }

    /** Pauses for the deployment and returns true when this is a new pause. */
    public function pauseFor(string $reason, int $deploymentId, ?string $commit): bool
    {
        if (($this->pause()['deployment_id'] ?? null) === $deploymentId) {
            return false;
        }

        $this->state['pause'] = ['reason' => $reason, 'deployment_id' => $deploymentId, 'commit' => $commit, 'paused_at' => Carbon::now()->toIso8601String()];

        return true;
    }

    public function clearPause(): void
    {
        $this->state['pause'] = null;
    }

    /** Keeps a commit from deploying automatically, such as the commit a rollback moved away from. */
    public function block(string $commit): void
    {
        $this->state['blocked'] = array_slice(array_values(array_unique([...$this->blocked(), $commit])), -self::BlockedLimit);
    }

    /** @return list<string> */
    public function blocked(): array
    {
        $blocked = $this->state['blocked'] ?? null;

        return is_array($blocked) ? array_values(array_filter($blocked, is_string(...))) : [];
    }

    /** Whether an alert of this kind already went out for this key, such as a commit. Records it when not. */
    public function alertOnce(string $kind, string $key): bool
    {
        $alerts = is_array($this->state['alerts'] ?? null) ? $this->state['alerts'] : [];

        if (($alerts[$kind] ?? null) === $key) {
            return false;
        }

        $this->state['alerts'] = [...$alerts, $kind => $key];

        return true;
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
