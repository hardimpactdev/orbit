<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Processes\ProcessLifecycleInput;
use App\Domain\Routes\RouteStatus;
use App\Domain\Schedules\ScheduleInstanceOperation;
use App\Domain\Schedules\ScheduleOperationCompletion;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Full native input and one terminal clone projection; no phase callbacks. */
final readonly class InstanceCloneProjection implements DirectOperationProjection, DirectOperationWorkflowInput, NativeOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['direct_provisioning' => $operation->input['direct_provisioning']];
    }

    public function workflowInput(Operation $operation): array
    {
        $instance = Instance::query()->with(['node', 'project'])->findOrFail($operation->target_id);
        $plan = ValidatedData::object($operation->input['direct_provisioning']);
        $candidate = ValidatedData::object($plan['candidate']);
        $ssh = app(SwampSshExecutionInput::class);
        $context = $instance->clone_completed_at === null
            ? app(InstanceEnvironmentContextResolver::class)->resolveForClone($instance, true)
            : app(InstanceEnvironmentContextResolver::class)->resolve($instance, true);
        $values = app(InstanceEnvironmentStore::class)->values($instance);
        $git = ['git_read' => app(InstanceGitHubReadTokens::class)->start($operation),
            'git_credential' => InstanceGitHubReadTokens::credentialReference($operation)];
        // The admitted Process and Schedule records, in the shapes their own install workflows take.
        $processes = $copies = [];
        foreach (ProcessInstanceOperation::members($operation) as $index => $member) {
            $native = app(ProcessLifecycleInput::class)->forInstanceMember($operation, $member);
            $processes[] = ['index' => $index, 'id' => $member['id'], 'intent' => $native['intent']];
            if (isset($native['execution_secrets']['environment'])) {
                $copies['member_'.$member['id']] = $native['execution_secrets']['environment'];
            }
        }
        $schedules = ValidatedData::object(app(ScheduleInstanceOperation::class)->input(self::schedules($operation))['intent'])['members'];

        return ['intent' => [...$plan, 'ssh_execution' => $ssh->forNode($instance->node), 'environment_path' => $context->path,
            'environment_keys' => array_keys($values), 'processes' => $processes, 'schedules' => $schedules,
            'candidate' => [...$candidate, 'ssh_execution' => $ssh->forNode(Node::query()->findOrFail(ValidatedData::integer($candidate['node_id'])))]],
            'execution_secrets' => [...$git, ...$copies, ...ValidatedData::object($operation->input['route_execution_secrets'] ?? []), 'environment' => app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'instance-clone-environment-operation-'.$operation->id,
                app(InstanceEnvironmentRenderer::class)->render($context, $values))]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $terminal = $operation->refresh()->status->terminal();
            $result = Arr::only(ValidatedData::object($data['result'] ?? []), ['instance_id', 'ready', 'branch', 'commit', 'php_version', 'laravel', 'candidate_commit', 'environment_keys', 'vp_environment', 'error_code']);
            if ($data['status'] === 'succeeded') {
                $result['warnings'] = $operation->input['warnings'] ?? [];
            }
            $projected = $this->direct->project($operation, $data, $result);
            if (! $terminal) {
                // An active Instance needs its authoritative Route, so the preview Route serves first.
                $this->completePreviewRoute($operation, $projected);
                $instance = Instance::query()->lockForUpdate()->findOrFail($operation->target_id);
                if ($projected->status === OperationStatus::Succeeded) {
                    $instance->forceFill(['status' => InstanceState::Active, 'provisioning_step' => 'active', 'first_setup_pending' => false,
                        'branch' => $result['branch'], 'starting_commit' => $result['commit'],
                        'selected_php_version' => $result['php_version'], 'source_is_laravel' => $result['laravel'],
                        'clone_candidate_commit' => $result['candidate_commit'], 'environment_owned_keys' => $result['environment_keys'],
                        'vp_environment' => ($result['vp_environment'] ?? null) === null ? null : [...ValidatedData::object($result['vp_environment']), 'status' => 'active', 'checkout_path' => $instance->checkout_path, 'previous' => null],
                        'clone_completed_at' => now(), 'failed_step' => null, 'error_code' => null])->save();
                } elseif ($projected->status->terminal()) {
                    $instance->update(['error_code' => $projected->error_code]);
                }
                $this->completeRuntime($operation, $projected);
            }

            return $projected;
        });
    }

    /** The preview Route serves once the clone succeeds, as route.create records it; a failure stays on the pending Route. */
    private function completePreviewRoute(Operation $operation, Operation $projected): void
    {
        $route = $operation->input['route_id'] ?? null;
        if (! is_int($route) || ! $projected->status->terminal() || $projected->status === OperationStatus::Cancelled) {
            return;
        }
        Route::query()->whereKey($route)->update($projected->status === OperationStatus::Succeeded
            ? ['status' => RouteStatus::Active, 'sites_published' => true, 'replacement_step' => null, 'failed_step' => null, 'error_code' => null]
            : ['failed_step' => 'instance-clone', 'error_code' => $projected->error_code]);
    }

    /** The Schedule install view of this clone: its admitted Schedule members under the shared members key. */
    private static function schedules(Operation $operation): Operation
    {
        $view = $operation->replicate();
        $view->id = $operation->id;
        $view->input = ['members' => $operation->input['schedule_members'] ?? []];

        return $view;
    }

    /** Process and Schedule records follow the clone's terminal status, as their own install kinds record them. */
    private function completeRuntime(Operation $operation, Operation $projected): void
    {
        if (! $projected->status->terminal() || $projected->status === OperationStatus::Cancelled) {
            return;
        }
        app(ProcessInstanceOperation::class)->completeMembers($operation, $projected->status, $projected->error_code, $projected->result ?? []);
        $outcome = match ($projected->status) {
            OperationStatus::Succeeded => OperationOutcome::succeeded(['ready' => true]),
            OperationStatus::Failed => OperationOutcome::failed((string) $projected->error_code, $projected->result ?? []),
            default => OperationOutcome::interrupted($projected->error_code, $projected->result ?? []),
        };
        $view = self::schedules($operation);
        foreach (ScheduleInstanceOperation::members($view) as $member) {
            app(ScheduleOperationCompletion::class)->complete(ScheduleInstanceOperation::member($view, $member), $outcome);
        }
    }
}
