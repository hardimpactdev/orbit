<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Domain\Extensions\ExtensionStore;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseFollowUp;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\GatewayRelease;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;
use Orbit\Extensions\AiRouter\Actions\AdmitAiRouterTaskGuardAction;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Domain\AiRouterTaskGuard;

/**
 * The `ai_router_guard` phase of each serving release ([The guard follows its config](/reference/ai-router#the-guard-follows-its-config)):
 * when the guard is in place and its config no longer matches the digest its last run applied, it applies the guard again. It never
 * brings back a guard that `ai-router:task-guard --remove` took off.
 */
final readonly class AiRouterTaskGuardRelease implements GatewayReleaseFollowUp
{
    public const string Phase = 'ai_router_guard';

    /** How long a guard or forwarder run may hold the guard before the phase gives up for this release. */
    public const int BusyMinutes = 30;

    public function __construct(private ExtensionStore $extensions, private AiRouterState $state, private AiRouterTaskGuard $guard,
        private AdmitAiRouterTaskGuardAction $admit) {}

    public function phase(): string
    {
        return self::Phase;
    }

    public function advance(GatewayRelease $release, array $previous): array
    {
        if (! in_array($previous['status'] ?? null, [null, 'pending'], true)) {
            return $previous;
        }
        if (isset($previous['operation_id'])) {
            return $this->observe($previous, Operation::query()->findOrFail(ValidatedData::integer($previous['operation_id'])));
        }
        $router = $this->guard->node();
        if (! $this->extensions->enabled('ai-router') || ! $this->state->enabled() || $router === null) {
            return ['status' => 'disabled'];
        }
        try {
            // The applied state, the forwarder snapshot, the digest and the admission are one writer transaction under the guard's lock.
            return $this->admit->ifChanged($router, 'release:'.GatewayReleaseCommit::id((string) $release->sha));
        } catch (ResourceOperationException $refused) {
            if ($refused->errorCode !== 'operation.target_busy') {
                return ['status' => 'failed', 'error_code' => $refused->errorCode];
            }
            // A guard or forwarder run holds the guard: the next pass asks again, for a bounded wait.
            $since = ValidatedData::nullableString($previous['busy_since'] ?? null) ?? now()->toISOString();
            $expired = Carbon::parse($since)->addMinutes(self::BusyMinutes)->isPast();

            return ['status' => $expired ? 'failed' : 'pending', 'error_code' => $refused->errorCode, 'busy_since' => $since];
        }
    }

    /**
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    private function observe(array $previous, Operation $operation): array
    {
        return match (true) {
            ! $operation->status->terminal() => $previous,
            $operation->status === OperationStatus::Succeeded => [...$previous, 'status' => 'current'],
            default => [...$previous, 'status' => 'failed', 'error_code' => $operation->error_code ?? 'ai_router.guard_failed'],
        };
    }
}
