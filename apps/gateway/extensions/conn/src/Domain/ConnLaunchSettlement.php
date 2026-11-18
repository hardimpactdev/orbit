<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationRecordCoordinator;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Orbit\Extensions\Conn\Actions\PublishConnLaunchAction;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnLaunchIntent;
use stdClass;
use Throwable;

/** Advances Conn's parent only from recorded receipts and the owning lifecycle admissions. */
final readonly class ConnLaunchSettlement implements OperationRecordCoordinator
{
    public function __construct(
        private ExtensionStore $extensions,
        private ConnLaunchState $state,
        private ConnLaunchInstances $instances,
        private ConnLaunchNativeWork $native,
        private PublishConnLaunchAction $publish,
        private CancelOperationAction $cancel,
        private NativeRunCancellation $nativeCancellation,
    ) {}

    public function sweep(): void
    {
        if (! $this->extensions->enabled('conn')) {
            return;
        }
        $launches = Operation::query()->where('kind', OperationKinds::ConnLaunch)
            ->where(static fn ($query) => $query->whereNotIn('status', OperationStatus::terminalValues())
                ->orWhere(static fn ($query) => $query->where('status', OperationStatus::Succeeded)
                    ->whereNotNull('state->launch_retry_requests->application')));
        foreach ($launches->lazyById() as $launch) {
            try {
                $this->advance($launch);
            } catch (Throwable $error) {
                $launch->refresh();
                Log::warning('The Conn launch could not advance.', ['operation_id' => $launch->id, 'exception_type' => $error::class]);
                if (! $launch->status->terminal()) {
                    $stage = match (true) {
                        $launch->cancel_requested_at !== null => ConnLaunchStage::Cleanup,
                        isset($launch->state['launch_thread']) => ConnLaunchStage::Publication,
                        isset($launch->state['dispatch_committed_at']) => ConnLaunchStage::FirstMessage,
                        default => ConnLaunchStage::Workspace,
                    };
                    $this->blocked($launch, $stage, $error instanceof ResourceOperationException ? $error->errorCode : null);
                }
            }
        }
    }

    public function advance(Operation $launch): void
    {
        if ($launch->cancel_requested_at !== null) {
            $this->cleanup($launch);

            return;
        }
        if ($launch->status === OperationStatus::Succeeded) {
            $this->application($launch, $this->instance($launch));

            return;
        }
        if (($launch->state['launch_phase'] ?? null) === 'needs_retry') {
            return;
        }
        if (isset($launch->state['dispatch_committed_at'])) {
            $this->handoff($launch);

            return;
        }

        $request = self::request($launch);
        $instance = $this->workspace($launch, $request);
        if ($instance === null) {
            return;
        }
        $environment = ConnEnvironment::query()->where('instance_id', $instance->id)->where('node_id', $launch->node_id)->sole();
        $this->application($launch->refresh(), $instance);
        $requested = $request->model_selection ?? null;
        $probe = $this->native->inspect($launch, $instance, $environment, $requested instanceof stdClass ? $requested : null);
        if ($probe === null || ! $this->nativeCancellation->settled($probe)) {
            return;
        }
        if ($probe->status !== OperationStatus::Succeeded) {
            $this->blocked($launch, ConnLaunchStage::Workspace, $probe->error_code);

            return;
        }

        $receipt = $probe->result ?? [];
        $selection = ($receipt['requested_provider_available'] ?? false) === true
            ? $requested : (object) ValidatedData::object($receipt['model_selection'] ?? null);
        if (! $selection instanceof stdClass) {
            $this->blocked($launch, ConnLaunchStage::Workspace);

            return;
        }
        $this->state->workspaceReady($launch->id);
        $this->native->dispatch($launch->refresh(), $instance, ValidatedData::string($receipt['environment_id']),
            ValidatedData::string($receipt['incarnation']), ValidatedData::string($receipt['project_id']), $selection);
    }

    private function workspace(Operation $launch, stdClass $request): ?Instance
    {
        $retry = Arr::has($launch->state ?? [], 'launch_retry_requests.launch');
        if (($launch->input['target_kind'] ?? null) === 'new') {
            $create = ConnLaunchChild::Create->operation($launch);
            if ($create === null || ($retry && $this->nativeCancellation->settled($create) && $create->status !== OperationStatus::Succeeded)) {
                $this->instances->create($launch, $request);

                return null;
            }
            if (! $this->nativeCancellation->settled($create)) {
                return null;
            }
            if ($create->status !== OperationStatus::Succeeded) {
                $this->blocked($launch, ConnLaunchStage::Workspace, $create->error_code);

                return null;
            }
        }

        $instance = $this->instance($launch);
        $wake = ConnLaunchChild::Wake->operation($launch);
        if ($wake !== null && ! $this->nativeCancellation->settled($wake)) {
            return null;
        }
        if ($wake !== null && $wake->status !== OperationStatus::Succeeded && ! $retry) {
            $this->blocked($launch, ConnLaunchStage::Workspace, $wake->error_code);

            return null;
        }
        if (($launch->input['target_kind'] ?? null) === 'existing'
            && ($instance->runtime()->firstOrFail()->power_state !== InstanceRuntimePowerState::Running
                || ($wake !== null && $wake->status !== OperationStatus::Succeeded && $retry))) {
            $this->instances->wake($launch, $instance);

            return null;
        }

        return $instance;
    }

    private function application(Operation $launch, Instance $instance): void
    {
        $complete = $this->instances->observeCompletion($launch, $instance);
        $retry = Arr::has($launch->refresh()->state ?? [], 'launch_retry_requests.application');
        if ($complete !== null && (! $retry || ! $this->nativeCancellation->settled($complete))) {
            return;
        }
        if ($instance->refresh()->status === InstanceState::SourceResolved && ($retry || ($launch->input['target_kind'] ?? null) === 'new')) {
            $this->instances->complete($launch, $instance);

            return;
        }
        if ($complete === null) {
            $this->state->applicationNotRequired($launch->id);
        }
    }

    private function handoff(Operation $launch): void
    {
        $dispatch = ConnLaunchNativeWork::attempt($launch, 'launch_dispatch_operation_id');
        if ($dispatch === null || ! $this->nativeCancellation->settled($dispatch)) {
            return;
        }
        $at = $dispatch->result['accepted_at'] ?? null;
        if ($dispatch->status === OperationStatus::Succeeded && is_string($at)) {
            $this->state->accepted($launch->id, $dispatch->id, Carbon::parse($at));
        }
        $this->native->clearDispatchCopy($launch->refresh(), $dispatch);
        $launch->refresh();
        if (isset($launch->state['launch_thread'])) {
            $this->publish->execute($launch->id);

            return;
        }
        if (Arr::get($dispatch->input, 'intent.action') !== 'launch'
            && ! Arr::has($launch->state ?? [], 'launch_retry_requests.launch')) {
            $this->blocked($launch, ConnLaunchStage::FirstMessage, $dispatch->error_code);

            return;
        }
        $this->native->dispatch($launch, $this->instance($launch), '', '', '', (object) []);
    }

    private function cleanup(Operation $launch): void
    {
        $probe = ConnLaunchNativeWork::attempt($launch, 'launch_inspect_operation_id');
        if ($probe !== null) {
            $settled = $this->nativeCancellation->settled($probe);
            if ($probe->status->terminal() && ! $settled) {
                $this->blocked($launch, ConnLaunchStage::Cleanup, $probe->error_code);
            }
            if (! $settled || ! $this->probeCredentialExpired($probe)) {
                return;
            }
        }

        foreach ([ConnLaunchChild::Create, ConnLaunchChild::Completion, ConnLaunchChild::Wake] as $role) {
            $record = Arr::get($launch->state ?? [], 'launch_children.'.$role->value);
            if (! is_array($record) || ($record['owned'] ?? null) !== true) {
                continue;
            }
            $operation = $role->operation($launch);
            if ($operation === null) {
                $this->blocked($launch, ConnLaunchStage::Cleanup);

                return;
            }
            if (! $this->nativeCancellation->settled($operation)) {
                if ($operation->status->terminal()) {
                    $this->nativeCancellation->request($operation);
                    $this->blocked($launch, ConnLaunchStage::Cleanup, $operation->error_code);
                } elseif ($operation->status === OperationStatus::Queued && $operation->attempts === 0
                    && $operation->cancel_requested_at === null) {
                    $this->cancel->execute($operation);
                }
                // A started remote step can keep working after the cancellation of its SSH client.
                // Wait for its native end before allowing teardown.

                return;
            }
        }

        if (Arr::get($launch->state ?? [], 'launch_children.'.ConnLaunchChild::Create->value.'.owned') !== true) {
            // A pre-existing Instance cannot be removed. The ordinary idle guard owns any later sleep.
            $this->state->cleanupVerified($launch->id);

            return;
        }
        $destroy = ConnLaunchChild::Destroy->operation($launch);
        if ($destroy !== null && ! $this->nativeCancellation->settled($destroy)) {
            return;
        }
        $instance = Instance::query()->find(ConnLaunchChild::instanceId($launch));
        if ($destroy?->status === OperationStatus::Succeeded && $instance === null) {
            $this->state->cleanupVerified($launch->id);

            return;
        }
        if ($instance === null) {
            $this->blocked($launch, ConnLaunchStage::Cleanup);

            return;
        }
        $this->instances->destroy($launch, $instance);
    }

    private function probeCredentialExpired(Operation $probe): bool
    {
        $observed = Arr::get($probe->state ?? [], 'native_timeout.native_terminal_observed_at');
        $cutoff = now()->subSeconds(120);

        return $probe->finished_at !== null && ! $probe->finished_at->isAfter($cutoff)
            && (! is_string($observed) || ! Carbon::parse($observed)->isAfter($cutoff));
    }

    private function blocked(Operation $launch, ConnLaunchStage $stage, ?string $code = null): void
    {
        $retry = Arr::get($launch->state ?? [], 'launch_retry_requests.launch.id');
        $this->state->blocked($launch->id, $stage, $code, is_string($retry) ? $retry : null);
    }

    private function instance(Operation $launch): Instance
    {
        return Instance::query()->where('node_id', $launch->node_id)->where('project_id', $launch->input['project_id'])
            ->findOrFail(ConnLaunchChild::instanceId($launch));
    }

    private static function request(Operation $launch): stdClass
    {
        $request = json_decode(ConnLaunchIntent::query()->findOrFail($launch->id)->request, flags: JSON_THROW_ON_ERROR);
        if (! $request instanceof stdClass) {
            throw new \LogicException('The admitted Conn launch has no request object.');
        }

        return $request;
    }
}
