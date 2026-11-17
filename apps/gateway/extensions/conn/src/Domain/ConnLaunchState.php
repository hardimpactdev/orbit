<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Models\Instance;
use App\Models\Operation;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Orbit\Extensions\Conn\Data\ConnLaunchSnapshot;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnLaunchIntent;
use SensitiveParameter;
use stdClass;

/** Durable transitions shared by Conn controls and native launch settlement. */
final readonly class ConnLaunchState
{
    public function __construct(private OperationStore $store, private ConnLaunchSnapshot $snapshot) {}

    public function locked(int $id): Operation
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Launch transitions require a transaction.');
        }

        return Operation::query()->where('kind', OperationKinds::ConnLaunch)->where('target_type', ConnLaunchAccess::Target)->lockForUpdate()->findOrFail($id);
    }

    /**
     * Only the admission portion belongs in the callback. Prepare outside the launch lock and start native work after commit.
     *
     * @param  Closure(Operation): array{operation: Operation, owned: bool}  $admit
     */
    public function admitChild(int $id, ConnLaunchChild $role, string $key, Closure $admit): ?Operation
    {
        return DB::transaction(function () use ($id, $role, $key, $admit): ?Operation {
            $launch = $this->locked($id);
            if (($launch->cancel_requested_at !== null) !== ($role === ConnLaunchChild::Destroy)
                || ($launch->status->terminal() && ($launch->status !== OperationStatus::Succeeded || $role !== ConnLaunchChild::Completion
                    || ($role->operationId($launch) !== null && ! Arr::has($launch->state ?? [], 'launch_retry_requests.application'))))) {
                return null;
            }

            $state = $launch->state ?? [];
            $recorded = Arr::get($state, 'launch_children.'.$role->value);
            if (is_array($recorded)) {
                $previous = $role->operation($launch) ?? throw new LogicException('The launch child is missing.');
                if (($recorded['key'] ?? null) === $key || ! $previous->status->terminal() || $previous->status === OperationStatus::Succeeded) {
                    return $previous;
                }
                $retryTarget = $role === ConnLaunchChild::Completion ? 'application' : 'launch';
                if ($role !== ConnLaunchChild::Destroy && ! Arr::has($state, 'launch_retry_requests.'.$retryTarget)) {
                    return $previous;
                }
            }

            if ($role === ConnLaunchChild::Destroy && (! self::owns($state, ConnLaunchChild::Create)
                || self::hasOpenOwnedWork($launch, [ConnLaunchChild::Create, ConnLaunchChild::Wake, ConnLaunchChild::Completion]))) {
                return null;
            }
            if ($role === ConnLaunchChild::Destroy) {
                $state = $this->retainCompletedStages($launch);
            }

            ['operation' => $child, 'owned' => $owned] = $admit($launch);
            $instanceId = ConnLaunchChild::instanceId($launch);
            $newTarget = ($launch->input['target_kind'] ?? null) === 'new';
            if (! $role->accepts($child->kind) || $child->target_type !== 'instance' || $child->node_id !== $launch->node_id
                || ($owned && ($child->triggered_by !== $launch->triggered_by || $child->idempotency_key !== $key))
                || ($role === ConnLaunchChild::Create && (! $newTarget || ! $owned))
                || ($role === ConnLaunchChild::Destroy && ! $owned)
                || ($instanceId === null ? $role !== ConnLaunchChild::Create || ! $this->ownsNewInstance($launch, $child) : $child->target_id !== $instanceId)) {
                throw new LogicException('The child does not match the admitted Conn launch target and owner.');
            }

            $state['launch_instance_id'] = $child->target_id;
            if (is_array($recorded)) {
                $path = 'launch_child_history.'.$role->value;
                $history = Arr::get($state, $path);
                Arr::set($state, $path, [...(is_array($history) ? $history : []), $recorded]);
            }
            Arr::set($state, 'launch_children.'.$role->value, ['id' => $child->id, 'key' => $key, 'kind' => $child->kind,
                'instance_id' => $child->target_id, 'node_id' => $child->node_id, 'owned' => $owned]);
            if ($role === ConnLaunchChild::Completion) {
                Arr::forget($state, 'launch_retry_requests.application');
            }
            if (in_array($role, [ConnLaunchChild::Create, ConnLaunchChild::Wake], true)) {
                Arr::forget($state, ['launch_retry_requests.launch', 'launch_stages.'.ConnLaunchStage::Workspace->value]);
            }
            if ($role === ConnLaunchChild::Destroy) {
                $state = self::stage($state, ConnLaunchStage::Cleanup, 'running', now());
            }
            $this->store->change($launch, ['state' => $state, 'started_at' => $launch->started_at ?? now()]);

            return $child;
        });
    }

    private function ownsNewInstance(Operation $launch, Operation $create): bool
    {
        return ($create->input['created'] ?? null) === true && Instance::query()->where('project_id', $launch->input['project_id'])
            ->where('name', $launch->input['target_name'])->where('node_id', $launch->node_id)->whereKey($create->target_id)
            ->whereHas('runtime', static fn ($runtime) => $runtime->where('clone_source_instance_id', $launch->input['default_instance_id']))->exists();
    }

    /** Record the exact child's readiness or proof from the read-only preparation of a running target. */
    public function workspaceReady(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $launch = $this->locked($id);
            if ($launch->cancel_requested_at !== null || $launch->status->terminal() || isset($launch->state['dispatch_committed_at'])
                || ($launch->state['launch_phase'] ?? null) === 'needs_retry') {
                return;
            }
            $workspace = $this->snapshot->workspaceStage($launch);
            if ($workspace['status'] !== 'succeeded') {
                $child = ConnLaunchChild::Wake->operation($launch) ?? ConnLaunchChild::Create->operation($launch);
                if ($child !== null && ($child->kind !== 'instance.start' || $child->status !== OperationStatus::Succeeded)) {
                    throw new LogicException('Workspace readiness must come from the admitted create or wake.');
                }
                $instanceId = ConnLaunchChild::instanceId($launch);
                if ($instanceId === null || ! ConnEnvironment::query()->where('instance_id', $instanceId)->where('node_id', $launch->node_id)->exists()) {
                    throw new LogicException('Workspace readiness requires the registered target.');
                }
                $workspace = ConnLaunchStage::Workspace->fact('succeeded', ConnLaunchStage::at($child?->started_at), ConnLaunchStage::at(now()));
            }
            $state = [...($launch->state ?? []), 'launch_phase' => 'preparing'];
            Arr::set($state, 'launch_stages.'.ConnLaunchStage::Workspace->value, $workspace);
            $this->store->change($launch, ['started_at' => $launch->started_at ?? now(), 'status' => OperationStatus::Running,
                'error_code' => null, 'state' => $state]);
        });
    }

    /** Called once preparation proves that application completion does not apply. */
    public function applicationNotRequired(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $launch = $this->locked($id);
            if ($launch->cancel_requested_at !== null || ($launch->status->terminal() && $launch->status !== OperationStatus::Succeeded)
                || ConnLaunchChild::instanceId($launch) === null || ConnLaunchChild::Completion->operationId($launch) !== null) {
                return;
            }
            $state = $launch->state ?? [];
            if (Arr::get($state, 'launch_stages.'.ConnLaunchStage::Application->value.'.status') !== 'skipped') {
                $this->store->change($launch, ['state' => self::stage($state, ConnLaunchStage::Application, 'skipped', now())]);
            }
        });
    }

    /**
     * Freeze the first message and admit its exact native handoff under the cancel fence. A later attempt is recovery only.
     *
     * @param  Closure(Operation, bool): Operation  $admit  Receives the parent and whether to reconcile rather than send initially.
     */
    public function admitDispatch(int $id, string $environmentId, string $incarnation, string $t3ProjectId,
        #[SensitiveParameter] stdClass $modelSelection, Closure $admit): ?Operation
    {
        return DB::transaction(function () use ($id, $environmentId, $incarnation, $t3ProjectId, $modelSelection, $admit): ?Operation {
            $launch = $this->locked($id);
            if ($launch->cancel_requested_at !== null || $launch->status->terminal() || isset($launch->state['launch_thread'])) {
                return null;
            }
            $state = $launch->state ?? [];
            $recovery = isset($state['dispatch_committed_at']);
            $previousId = $state['launch_dispatch_operation_id'] ?? null;
            if ($recovery && is_int($previousId)) {
                $previous = Operation::query()->findOrFail($previousId);
                if (! $previous->status->terminal() || ! Arr::has($state, 'launch_retry_requests.launch')) {
                    return $previous;
                }
            }
            $instanceId = ConnLaunchChild::instanceId($launch);
            if ($instanceId === null) {
                throw new LogicException('Dispatch requires the admitted Instance.');
            }

            if (! $recovery) {
                if (($state['launch_phase'] ?? null) === 'needs_retry') {
                    return null;
                }
                $workspace = $this->snapshot->workspaceStage($launch);
                if ($workspace['status'] !== 'succeeded'
                    || ! ConnEnvironment::query()->where('environment_id', $environmentId)->where('instance_id', $instanceId)
                        ->where('node_id', $launch->node_id)->exists()) {
                    throw new LogicException('Dispatch requires proof of readiness for the registered target.');
                }
                $this->freeze($launch, $environmentId, $incarnation, $t3ProjectId, $modelSelection);
                $state['dispatch_committed_at'] = ConnLaunchStage::at(now());
                Arr::set($state, 'launch_stages.'.ConnLaunchStage::Workspace->value, $workspace);
                $this->store->change($launch, ['state' => $state]);
            }

            $child = $admit($launch, $recovery);
            if ($child->kind !== ConnLaunchDispatchSource::Kind || $child->node_id !== $launch->node_id || $child->target_type !== 'instance'
                || $child->target_id !== $instanceId || $child->triggered_by !== $launch->triggered_by
                || ($child->input['launch_operation_id'] ?? null) !== $launch->id || $child->idempotency_key === null || $child->id === $previousId) {
                throw new LogicException('The handoff must bind its originating launch, Instance, Node and caller.');
            }
            Arr::forget($state, 'launch_retry_requests.launch');
            $state['launch_dispatch_operation_id'] = $child->id;
            $state['launch_phase'] = 'dispatching';
            $this->store->change($launch, ['status' => OperationStatus::Running, 'started_at' => $launch->started_at ?? now(),
                'error_code' => null, 'state' => self::stage($state, ConnLaunchStage::FirstMessage, 'running', now())]);

            return $child;
        });
    }

    private function freeze(Operation $launch, string $environmentId, string $incarnation, string $t3ProjectId, #[SensitiveParameter] stdClass $model): void
    {
        if ($incarnation === '' || $t3ProjectId === '' || strlen($incarnation) > 255 || strlen($t3ProjectId) > 255
            || strlen(json_encode($model, JSON_THROW_ON_ERROR)) > 16_384) {
            throw new LogicException('The dispatch binding is missing or exceeds its limit.');
        }
        $intent = ConnLaunchIntent::query()->findOrFail($launch->id);
        $request = json_decode($intent->request, flags: JSON_THROW_ON_ERROR);
        if (! $request instanceof stdClass || $intent->dispatch !== null) {
            throw new LogicException('The launch is missing its unfrozen private request.');
        }
        $intent->update(['dispatch' => json_encode(['incarnation' => $incarnation, 'environment_id' => $environmentId, 'request' => [
            'commandId' => $request->command_id,
            'threadId' => $request->thread_id,
            'projectId' => $t3ProjectId,
            'title' => 'Orbit launch',
            'generateTitle' => true,
            'modelSelection' => $model,
            'runtimeMode' => $request->runtime_mode ?? 'full-access',
            'interactionMode' => $request->interaction_mode ?? 'default',
            'workspaceStrategy' => ['type' => 'root'],
            'creationSource' => $launch->input['source'],
            'initialMessage' => ['messageId' => $request->message_id, 'text' => $request->prompt, 'attachments' => []],
        ]], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);
    }

    /** Record only the timestamp of an acceptance verified by the native handoff; the identities come from the frozen request. */
    public function accepted(int $id, int $dispatchId, DateTimeInterface $acceptedAt): void
    {
        DB::transaction(function () use ($id, $dispatchId, $acceptedAt): void {
            $launch = $this->locked($id);
            $state = $launch->state ?? [];
            if (isset($state['launch_thread'])) {
                return;
            }
            if ($launch->cancel_requested_at !== null || ! isset($state['dispatch_committed_at']) || ($state['launch_dispatch_operation_id'] ?? null) !== $dispatchId) {
                throw new LogicException('Acceptance does not belong to the admitted dispatch.');
            }
            $intent = ConnLaunchIntent::query()->findOrFail($id);
            $frozen = json_decode($intent->dispatch ?? '', flags: JSON_THROW_ON_ERROR);
            if (! $frozen instanceof stdClass || ! $frozen->request instanceof stdClass || ! $frozen->request->initialMessage instanceof stdClass) {
                throw new LogicException('The accepted dispatch has no frozen request.');
            }
            $state['launch_thread'] = json_encode([
                'environment_id' => $frozen->environment_id,
                'project_id' => $frozen->request->projectId,
                'thread_id' => $frozen->request->threadId,
                'message_id' => $frozen->request->initialMessage->messageId,
                'model_selection' => $frozen->request->modelSelection,
                'accepted_at' => $acceptedAt->format(DateTimeInterface::RFC3339_EXTENDED),
            ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            Arr::forget($state, 'launch_retry_requests.launch');
            $state['launch_phase'] = 'publishing';
            $state = self::stage($state, ConnLaunchStage::FirstMessage, 'succeeded', now());
            $this->store->change($launch, ['state' => self::stage($state, ConnLaunchStage::Publication, 'running', now()),
                'status' => OperationStatus::Running, 'error_code' => null]);
            $intent->delete();
        });
    }

    /** Called after idempotent index and profile attachment publication. */
    public function published(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $launch = $this->locked($id);
            if ($launch->status->terminal()) {
                return;
            }
            $state = $launch->state ?? [];
            if (! isset($state['launch_thread']) || $launch->cancel_requested_at !== null) {
                throw new LogicException('Publication requires a verified first message.');
            }
            Arr::forget($state, 'launch_retry_requests.launch');
            $state['launch_phase'] = 'ready';
            $state = self::stage($state, ConnLaunchStage::Cleanup, 'skipped', now());
            $this->store->change($launch, ['state' => self::stage($state, ConnLaunchStage::Publication, 'succeeded', now()),
                'status' => OperationStatus::Succeeded, 'finished_at' => now(), 'error_code' => null]);
        });
    }

    public function blocked(int $id, ConnLaunchStage $stage, ?string $code = null, ?string $retryId = null): void
    {
        DB::transaction(function () use ($id, $stage, $code, $retryId): void {
            $launch = $this->locked($id);
            $pending = Arr::get($launch->state ?? [], 'launch_retry_requests.launch.id');
            if ($pending !== null && $pending !== $retryId) {
                return;
            }
            $stageAllowed = match ($stage) {
                ConnLaunchStage::Workspace => ! isset($launch->state['dispatch_committed_at']),
                ConnLaunchStage::FirstMessage => isset($launch->state['dispatch_committed_at']) && ! isset($launch->state['launch_thread']),
                ConnLaunchStage::Publication => isset($launch->state['launch_thread']),
                ConnLaunchStage::Cleanup => true,
                ConnLaunchStage::Application => false,
            };
            if ($launch->status->terminal() || ($launch->cancel_requested_at !== null) !== ($stage === ConnLaunchStage::Cleanup) || ! $stageAllowed) {
                return;
            }
            $workspace = $stage === ConnLaunchStage::Workspace ? $this->snapshot->workspaceStage($launch) : null;
            $state = self::stage($launch->state ?? [], $stage, 'failed', now(), $code);
            $errorCode = Arr::get($state, 'launch_stages.'.$stage->value.'.error.code');
            if ($workspace !== null && $workspace['status'] === 'succeeded') {
                Arr::set($state, 'launch_stages.'.$stage->value, $workspace);
            }
            Arr::forget($state, 'launch_retry_requests.launch');
            $state['launch_phase'] = $stage === ConnLaunchStage::Cleanup ? 'cancelling' : 'needs_retry';
            $this->store->change($launch, ['state' => $state, 'status' => OperationStatus::Waiting, 'error_code' => $errorCode]);
        });
    }

    /** @return array<string, mixed> */
    public function retainCompletedStages(Operation $launch): array
    {
        $state = $launch->state ?? [];
        foreach ($this->snapshot->stages($launch, ConnLaunchChild::Completion->operation($launch)) as $name => $fact) {
            if (! in_array($fact['status'], ['pending', 'running'], true)) {
                Arr::set($state, 'launch_stages.'.$name, $fact);
            }
        }

        return $state;
    }

    /** A terminal child is not cleanup evidence: the settlement caller must verify the owned effects are gone first. */
    public function cleanupVerified(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $launch = $this->locked($id);
            if ($launch->cancel_requested_at === null || $launch->status->terminal()) {
                return;
            }
            $state = $launch->state ?? [];
            if (self::hasOpenOwnedWork($launch, ConnLaunchChild::cases())) {
                throw new LogicException('Launch cleanup cannot finish while owned work remains open.');
            }
            if (self::owns($state, ConnLaunchChild::Create)
                && Instance::query()->whereKey(ConnLaunchChild::instanceId($launch))->exists()) {
                throw new LogicException('Launch cleanup cannot finish while the owned Instance remains.');
            }

            $stages = $this->snapshot->stages($launch, ConnLaunchChild::Completion->operation($launch));
            foreach ($stages as $name => $fact) {
                $stage = ConnLaunchStage::from($name);
                Arr::set($state, 'launch_stages.'.$name, $fact);
                if ($stage !== ConnLaunchStage::Cleanup && in_array($fact['status'], ['pending', 'running'], true)) {
                    $state = self::stage($state, $stage, 'cancelled', now());
                }
            }
            $state['launch_phase'] = 'cancelled';
            $this->store->change($launch, ['state' => self::stage($state, ConnLaunchStage::Cleanup, 'succeeded', now()),
                'status' => OperationStatus::Cancelled, 'finished_at' => now(), 'error_code' => null]);
            ConnLaunchIntent::query()->whereKey($id)->delete();
        });
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function stage(array $state, ConnLaunchStage $stage, string $status, DateTimeInterface $at, ?string $code = null): array
    {
        $path = 'launch_stages.'.$stage->value;
        $previous = $stage->recorded(Arr::get($state, $path));
        $time = ConnLaunchStage::at($at);
        Arr::set($state, $path, $stage->fact($status,
            $status === 'running' ? $time : $previous['started_at'], $status === 'running' ? null : $time, $code));

        return $state;
    }

    /** @param array<string, mixed> $state */
    private static function owns(array $state, ConnLaunchChild $role): bool
    {
        return Arr::get($state, 'launch_children.'.$role->value.'.owned') === true;
    }

    /** @param list<ConnLaunchChild> $roles */
    private static function hasOpenOwnedWork(Operation $launch, array $roles): bool
    {
        return array_any($roles, static fn (ConnLaunchChild $role): bool => self::owns($launch->state ?? [], $role)
            && $role->operation($launch)?->status->terminal() !== true);
    }
}
