<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Node;
use App\Models\Operation;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Orbit\Extensions\Conn\Data\ConnLaunchSnapshot;
use Orbit\Extensions\Conn\Domain\ConnLaunchAccess;
use Orbit\Extensions\Conn\Domain\ConnLaunchStage;
use Orbit\Extensions\Conn\Domain\ConnLaunchState;

final readonly class ControlConnLaunchAction
{
    public function __construct(private ConnLaunchAccess $access, private ConnLaunchState $state, private ConnLaunchSnapshot $snapshot,
        private OperationStore $store) {}

    /** @return array<string, mixed> */
    public function cancel(Node $caller, int $id, string $requestId): array
    {
        return $this->control($caller, $id, function (Operation $launch) use ($caller, $requestId): void {
            $state = $launch->state ?? [];
            if (isset($state['dispatch_committed_at'])) {
                throw new ResourceOperationException('conn.launch_handoff_committed', 'The handoff to T3 is committed. Use the thread controls once it is accepted.', 409);
            }
            if ($launch->cancel_requested_at !== null) {
                return;
            }
            if ($launch->status->terminal()) {
                throw new ResourceOperationException('conn.launch_cancel_unavailable', 'The launch has ended.', 409);
            }
            $now = now();
            $state = $this->state->retainCompletedStages($launch);
            unset($state['launch_retry_requests']);
            $state['launch_phase'] = 'cancelling';
            $state['launch_cancellation'] = ['node_id' => $caller->id, 'request_id' => $requestId, 'requested_at' => ConnLaunchStage::at($now)];
            Arr::set($state, 'launch_stages.'.ConnLaunchStage::Cleanup->value, ConnLaunchStage::Cleanup->fact('running', ConnLaunchStage::at($now)));
            $this->store->change($launch, ['state' => $state, 'cancel_requested_at' => $now, 'status' => OperationStatus::Waiting,
                'started_at' => $launch->started_at ?? $now, 'error_code' => null]);
            $this->state->cancelQueuedWake($launch, executorOnly: true);
            ReconcileSwampOperations::dispatch()->afterCommit();
        });
    }

    /** @return array<string, mixed> */
    public function retry(Node $caller, int $id, string $target, string $requestId): array
    {
        return $this->control($caller, $id, function (Operation $launch) use ($caller, $target, $requestId): void {
            $state = $launch->state ?? [];
            if (Arr::has($state, 'launch_retry_requests.'.$target)) {
                return;
            }
            $projection = $this->snapshot->project($launch);
            $action = Arr::get($projection, 'actions.retry_'.$target);
            $action = is_array($action) ? $action : [];
            if (($action['available'] ?? false) !== true) {
                throw new ResourceOperationException('conn.launch_retry_unavailable',
                    is_string($action['reason'] ?? null) ? $action['reason'] : 'The launch cannot retry this work.', 409,
                    details: is_string($action['requires'] ?? null) ? ['requires' => $action['requires']] : []);
            }

            $retry = ['id' => (string) Str::uuid(), 'node_id' => $caller->id, 'request_id' => $requestId, 'requested_at' => ConnLaunchStage::at(now())];
            Arr::set($state, 'launch_retry_requests.'.$target, $retry);
            Arr::set($state, 'launch_last_retry.'.$target, $retry);
            if ($target === 'launch') {
                $state['launch_phase'] = match (true) {
                    isset($state['launch_thread']) => 'publishing',
                    isset($state['dispatch_committed_at']) => 'dispatching',
                    default => 'preparing',
                };
            }
            $this->store->change($launch, ['state' => $state, ...($target === 'launch' ? ['status' => OperationStatus::Waiting, 'error_code' => null] : [])]);
            ReconcileSwampOperations::dispatch()->afterCommit();
        });
    }

    /**
     * @param  Closure(Operation): void  $mutate
     * @return array<string, mixed>
     */
    private function control(Node $caller, int $id, Closure $mutate): array
    {
        return DB::transaction(function () use ($caller, $id, $mutate): array {
            $caller = $this->access->lockActivePeer($caller);
            $profileId = $this->access->profileId($caller, lock: true);
            $launch = $this->state->locked($id);
            $this->access->assertVisible($caller, $profileId, $launch);
            $mutate($launch);

            return $this->snapshot->project($launch);
        });
    }
}
