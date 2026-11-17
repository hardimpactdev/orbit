<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Data\Operations\OperationData;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationStatus;
use App\Domain\ProjectDefinitions\DefinitionEnvironment;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Process;
use App\Models\ProcessDefinition;
use Illuminate\Support\Arr;
use Orbit\Extensions\Conn\Domain\ConnLaunchChild;
use Orbit\Extensions\Conn\Domain\ConnLaunchStage;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

/**
 * @phpstan-import-type Stage from ConnLaunchStage
 *
 * @phpstan-type Action array{available: bool, reason: ?string, requires: ?string}
 */
final readonly class ConnLaunchSnapshot
{
    public function __construct(private InstantiateProjectRuntimeDefinitionsAction $definitions) {}

    /** @return array<string, mixed> */
    public function project(Operation $operation): array
    {
        $summary = array_intersect_key(OperationData::summary($operation)->toArray(), array_flip([
            'id', 'kind', 'status', 'created_at', 'started_at', 'finished_at', 'cancel_requested', 'poll_after_seconds', 'error_code',
        ]));
        $input = $operation->input;
        $completion = ConnLaunchChild::Completion->operation($operation);
        $stages = $this->stages($operation, $completion);
        $phase = self::phase($operation, $stages);
        $receipt = $operation->state['launch_thread'] ?? null;
        if ($phase === 'ready' && (in_array($stages['application_preview']['status'], ['pending', 'running'], true)
            || Arr::has($operation->state ?? [], 'launch_retry_requests.application'))) {
            $summary['poll_after_seconds'] = $completion === null ? 2 : OperationData::summary($completion)->pollAfterSeconds ?? 2;
        }

        return [...$summary,
            'launch_id' => $input['launch_id'],
            'profile_id' => $operation->target_id,
            'project_id' => $input['project_id'],
            'instance_id' => ConnLaunchChild::instanceId($operation),
            'workspace_id' => $input['workspace_id'],
            'target_node_id' => $operation->node_id,
            'initiator_node_id' => $operation->callerNodeId(),
            'source' => $input['source'],
            'phase' => $phase,
            'stages' => $stages,
            'readiness' => [
                'workspace_server' => self::readiness($stages['workspace_server']),
                'first_message' => is_string($receipt) ? true : ($operation->cancel_requested_at === null ? null : false),
                'application_preview' => self::readiness($stages['application_preview']),
            ],
            'actions' => $this->actions($operation, $phase, $completion),
            'children' => array_column(array_map(static fn (ConnLaunchChild $child): array => [$child->value, $child->operationId($operation)], ConnLaunchChild::cases()), 1, 0),
            'thread' => is_string($receipt) ? json_decode($receipt, flags: JSON_THROW_ON_ERROR) : null,
        ];
    }

    /** @return array{cancel: Action, retry_launch: Action, retry_application: Action} */
    private function actions(Operation $launch, string $phase, ?Operation $completion): array
    {
        $cancel = match (true) {
            isset($launch->state['dispatch_committed_at']) => self::action('The handoff is committed. Use the thread controls once it is accepted.'),
            $launch->cancel_requested_at !== null => self::action('Cancellation is already recorded.'),
            $launch->status->terminal() => self::action('The launch has ended.'),
            default => self::action(),
        };
        $retryLaunch = match (true) {
            Arr::has($launch->state ?? [], 'launch_retry_requests.launch') => self::action('A launch retry is already recorded.'),
            $phase !== 'needs_retry' => self::action('The launch does not need a retry.'),
            default => self::action(),
        };
        $retryApplication = match (true) {
            $phase !== 'ready' => self::action('The thread must be ready before retrying the application.'),
            Arr::has($launch->state ?? [], 'launch_retry_requests.application') => self::action('An application retry is already recorded.'),
            $completion === null || ! $completion->status->terminal() || $completion->status === OperationStatus::Succeeded => self::action('The application does not need a retry.'),
            default => $this->applicationAction($launch, $completion),
        };

        return ['cancel' => $cancel, 'retry_launch' => $retryLaunch, 'retry_application' => $retryApplication];
    }

    /** @return Action */
    private function applicationAction(Operation $launch, Operation $completion): array
    {
        $instance = Instance::query()->where('node_id', $launch->node_id)->find($completion->target_id);
        if ($instance === null || $instance->status !== InstanceState::SourceResolved) {
            return self::action('The Instance no longer has an application to complete.');
        }
        if (Arr::get($completion->result ?? [], 'recovery.retry.requires') === 'project_process_definition'
            && ! $this->definitionChanged($completion, $instance)) {
            return self::action('Update the failing Project process definition before retrying.', 'project_process_definition');
        }

        return self::action();
    }

    private function definitionChanged(Operation $completion, Instance $instance): bool
    {
        $processId = Arr::get($completion->result ?? [], 'process_failure.process_id');
        $units = Arr::get($completion->input, 'direct_provisioning.container.processes');
        $failedUnit = is_int($processId) && is_array($units) ? array_column($units, null, 'id')[$processId] ?? null : null;
        if (! is_int($processId) || ! is_array($failedUnit)) {
            return false;
        }

        $process = Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)->find($processId);
        if ($process === null || $process->status === LifecycleStatus::Removing) {
            return true;
        }
        if ($process->source_definition_id === null) {
            return false;
        }
        $definition = ProcessDefinition::query()->where('project_id', $instance->project_id)->find($process->source_definition_id);
        if ($definition === null || ! in_array(DefinitionEnvironment::Development->value, $definition->environments, true)) {
            return true;
        }
        try {
            $expected = $this->definitions->developmentRetryUnit($instance, $definition, $process);
            ksort($expected);
            ksort($failedUnit);

            return array_keys($expected) === array_keys($failedUnit) && $expected !== $failedUnit;
        } catch (ResourceOperationException) {
            return false;
        }
    }

    /** @return Action */
    private static function action(?string $reason = null, ?string $requires = null): array
    {
        return ['available' => $reason === null, 'reason' => $reason, 'requires' => $requires];
    }

    /** @return array<string, Stage> */
    public function stages(Operation $launch, ?Operation $completion): array
    {
        $stages = [];
        foreach (ConnLaunchStage::cases() as $stage) {
            $stages[$stage->value] = $stage->recorded(Arr::get($launch->state ?? [], 'launch_stages.'.$stage->value));
        }
        $stages['workspace_server'] = $this->workspaceStage($launch);
        if ($completion !== null && $launch->status !== OperationStatus::Cancelled) {
            $stages['application_preview'] = self::childStage(ConnLaunchStage::Application, $completion, ($completion->result['ready'] ?? null) === true);
        }
        $destroy = ConnLaunchChild::Destroy->operation($launch);
        if ($destroy !== null && $destroy->status->terminal() && $destroy->status !== OperationStatus::Succeeded && $stages['cleanup']['status'] !== 'succeeded') {
            $stages['cleanup'] = ConnLaunchStage::Cleanup->fact('failed', ConnLaunchStage::at($destroy->started_at),
                ConnLaunchStage::at($destroy->finished_at), $destroy->error_code);
        }

        return $stages;
    }

    /** @return Stage */
    public function workspaceStage(Operation $launch): array
    {
        $stage = ConnLaunchStage::Workspace;
        $recorded = $stage->recorded(Arr::get($launch->state ?? [], 'launch_stages.'.$stage->value));
        if ($recorded['status'] === 'succeeded' || $launch->status === OperationStatus::Cancelled) {
            return $recorded;
        }
        $workspace = ConnLaunchChild::Wake->operation($launch) ?? ConnLaunchChild::Create->operation($launch);
        if ($workspace === null || ($workspace->kind === 'instance.start' && $workspace->status === OperationStatus::Succeeded)) {
            return $recorded;
        }
        $child = self::childStage($stage, $workspace, self::registered($workspace));

        return $child['status'] === 'succeeded' || in_array($recorded['status'], ['pending', 'running'], true) ? $child : $recorded;
    }

    private static function registered(Operation $workspace): bool
    {
        if ($workspace->status !== OperationStatus::Succeeded) {
            return false;
        }
        $environment = ConnEnvironment::query()->where('instance_id', $workspace->target_id)->where('node_id', $workspace->node_id);
        if ($workspace->kind === 'instance.create') {
            $id = Arr::get($workspace->result ?? [], 't3.environment_id');
            if (! is_string($id)) {
                return false;
            }
            $environment->where('environment_id', $id);
        }

        return $environment->exists();
    }

    /** @return Stage */
    private static function childStage(ConnLaunchStage $stage, Operation $child, bool $ready): array
    {
        $status = match ($child->status) {
            OperationStatus::Queued, OperationStatus::Waiting => $child->started_at === null ? 'pending' : 'running',
            OperationStatus::Running => 'running',
            OperationStatus::Succeeded => $ready ? 'succeeded' : 'failed',
            OperationStatus::Cancelled => 'cancelled',
            OperationStatus::Failed, OperationStatus::Interrupted => 'failed',
        };

        return $stage->fact($status, ConnLaunchStage::at($child->started_at), ConnLaunchStage::at($child->finished_at), $child->error_code);
    }

    /** @param array<string, Stage> $stages */
    private static function phase(Operation $operation, array $stages): string
    {
        if ($operation->status === OperationStatus::Cancelled) {
            return 'cancelled';
        }
        if ($operation->cancel_requested_at !== null) {
            return 'cancelling';
        }
        $phase = $operation->state['launch_phase'] ?? 'preparing';
        if ($phase === 'preparing' && ! Arr::has($operation->state ?? [], 'launch_retry_requests.launch')
            && in_array($stages['workspace_server']['status'], ['failed', 'cancelled'], true)) {
            return 'needs_retry';
        }

        return is_string($phase) ? $phase : 'preparing';
    }

    /** @param Stage $stage */
    private static function readiness(array $stage): ?bool
    {
        return match ($stage['status']) {
            'succeeded' => true,
            'failed', 'cancelled' => false,
            default => null,
        };
    }
}
