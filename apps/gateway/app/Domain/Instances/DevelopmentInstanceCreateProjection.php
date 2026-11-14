<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\StartInstanceCompleteAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\ContainerWorkspaceRead;
use App\Infrastructure\Swamp\SwampVaultExecutionCopy;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Project the original native terminal receipt once; no running phase callbacks. A container clone's create ends when its thread is
 * usable: its success records the T3 server and the workspace baseline, keeps the clone `source_resolved`, and admits `instance.complete`
 * in the same transaction (docs/reference/development-containers.md#create). Its result says what became of its address
 * (`route_publication`, ClonePublication).
 */
final readonly class DevelopmentInstanceCreateProjection implements DirectOperationProjection
{
    /** A clone whose run succeeded fails its create when the Gateway cannot read its workspace baseline before the user can work. */
    public const string BaselineUnavailable = 'instance.workspace_baseline_unavailable';

    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return app(NativeDevelopmentInstanceInput::class)->create($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        // A clone's T3 session is read from the vault, and its workspace baseline from its Node, before the transaction, so no lock waits
        // on the network.
        $container = ValidatedData::object($operation->input['direct_provisioning'] ?? [])['container'] ?? null;
        $clone = $container === null ? null : (ValidatedData::object($container)['clone'] ?? null);
        $alreadyActive = (ValidatedData::object($operation->input['direct_provisioning'] ?? [])['already_active'] ?? false) === true;
        $handover = $clone !== null && $data['status'] === 'succeeded' && ! $alreadyActive;
        $server = $handover ? $this->server(ValidatedData::object(ValidatedData::object($clone)['t3']), ValidatedData::object($data['result'] ?? [])) : null;
        $baseline = $handover && ! $operation->status->terminal() ? $this->baseline($operation) : null;
        if ($baseline === false) {
            // The run succeeded, but nothing proves what the workspace held before the user could work: the create fails, and no
            // completion starts. The run ended without its container stop, so the container keeps running for a retry or a destroy.
            $data = [...$data, 'status' => 'failed', 'error_code' => self::BaselineUnavailable,
                'failure' => ['failed_step' => 'clone-register', 'detail' => 'The workspace baseline could not be read.', 'stderr_recorded' => false],
                'result' => [...array_diff_key(ValidatedData::object($data['result'] ?? []), ['t3' => true]), 'ready' => false,
                    'failed_step' => 'register', 'error_code' => self::BaselineUnavailable]];
            $server = $baseline = null;
            $handover = false;
        }

        return DB::transaction(function () use ($operation, $data, $container, $server, $baseline, $handover): Operation {
            $operation->refresh();
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $result = ValidatedData::object($data['result'] ?? []);
            if (is_array($container) && isset($container['club_key'])) {
                // What this nested-fleet start did with its Club key, from the run's own word.
                $result['nested_fleet_club_key'] = NestedFleetClubKey::outcome($result['club_key'] ?? null, $result['error_code'] ?? $data['error_code'] ?? null);
            }
            unset($result['club_key']);
            // A removal admitted during the run cancelled it and owns the Instance: the run's end changes no record of it, and no completion
            // starts (docs/reference/conn.md#remove-an-instance).
            if (Instance::query()->find($operation->target_id)?->status === InstanceState::Removing) {
                return $this->direct->project($operation, $data, $result);
            }
            if (is_array($operation->input['branch_selection'] ?? null)) {
                $selection = InstanceBranchSelection::fromArray(ValidatedData::object($operation->input['branch_selection']));
                $result['branch'] = $selection->target;
                $result['base_branch'] = $selection->base;
            }
            if ($data['status'] === 'succeeded') {
                $result['mail_keys_set'] = ValidatedData::stringList($operation->input['mail_keys_set'] ?? []);
            }
            if ($container !== null && in_array($data['status'], ['failed', 'cancelled'], true)) {
                $recovery = $this->recovery($operation, ValidatedData::object($container));
                if ($recovery === null) {
                    return $this->direct->project($operation, $data, $result);
                }
                $result['recovery'] = $recovery;
            }
            $instance = app(InstanceLifecycleState::class)->instance($operation);
            if ((ValidatedData::object($operation->input['direct_provisioning'] ?? [])['already_active'] ?? false) === true) {
                return $this->direct->project($operation, $data, $result);
            }
            if ($data['status'] === 'succeeded' && (($result['ready'] ?? false) !== true || ($result['instance_id'] ?? null) !== $instance->id || ! is_string($result['commit'] ?? null))) {
                throw new ResourceOperationException('instance.result_invalid', 'Native creation did not prove its complete terminal result.');
            }
            if (! $operation->status->terminal()) {
                // The run returns only the checked-out commit; branch, profile and environment are admitted facts.
                $input = $operation->input;
                if (is_string($result['commit'] ?? null)) {
                    $instance->update(['status' => InstanceState::SourceResolved, 'provisioning_step' => 'php-selected',
                        'branch' => ValidatedData::string(ValidatedData::object(ValidatedData::object($input['direct_provisioning'])['resolve'])['branch']),
                        'starting_commit' => ValidatedData::string($result['commit']),
                        'selected_php_version' => ValidatedData::nullableString($input['php_version'] ?? null), 'source_is_laravel' => ($input['laravel'] ?? false) === true,
                        ...(($input['seed_commit'] ?? null) !== null ? ['source_layout' => InstanceSourceLayout::Worktree] : [])]);
                }
                if ($data['status'] === 'succeeded' || in_array($result['failed_step'] ?? null, ['setup', 'processes', 'route', 'deploy', 'seed'], true)) {
                    $instance->forceFill(['environment_owned_keys' => ValidatedData::stringList($input['environment_keys'] ?? [])])->save();
                }
                $route = ($input['route_id'] ?? null) === null ? null : Route::query()->findOrFail(ValidatedData::integer($input['route_id']));
                // A clone's address never decides its create. A published one is active whatever the create's end, also when the progress
                // event of `clone-route` was lost; this transaction's terminal state frees the Route Nodes. A failed one is marked, and the
                // completion publishes it again (docs/reference/development-containers.md#the-address-during-the-create).
                $publication = ClonePublication::outcome($operation, $result);
                if ($publication !== null) {
                    $result[ClonePublication::Field] = $publication;
                    match ($publication) {
                        'published' => app(ClonePublication::class)->activate($operation),
                        'failed' => app(ClonePublication::class)->failed($operation),
                        default => null,
                    };
                }
                if ($handover && $server !== null) {
                    // A clone's thread is usable: it runs, the Conn layer records its T3 server, and the baseline records what the workspace
                    // held before any pairing (development-containers.md#workspace-work-state). It stays `source_resolved`: only its
                    // completion makes it active.
                    $instance->update(['failed_step' => null, 'error_code' => null]);
                    $runtime = $instance->runtime()->lockForUpdate()->firstOrFail();
                    $runtime->update(['power_state' => InstanceRuntimePowerState::Running,
                        ...(is_array($baseline) && $runtime->workspace_baseline === null ? ['workspace_baseline' => $baseline, 'workspace_baseline_at' => now()] : [])]);
                    app(InstanceLifecycleContributions::class)->projectCreate($instance, $server);
                } elseif ($data['status'] === 'succeeded') {
                    // An active routed Instance needs its active Route, so the Route turns active first.
                    $route?->update(['status' => RouteStatus::Active, 'sites_published' => true, 'failed_step' => null, 'error_code' => null]);
                    $instance->update(['status' => InstanceState::Active, 'provisioning_step' => 'active', 'first_setup_pending' => false, 'failed_step' => null, 'error_code' => null,
                        ...($container === null ? [] : ['development_projection_pending' => false])]);
                    if ($container !== null) {
                        // A container default runs, and clones start at the seed snapshot the run took of its commit.
                        $instance->runtime()->firstOrFail()->update(['power_state' => InstanceRuntimePowerState::Running, 'seed_commit' => ValidatedData::string($result['commit'])]);
                    }
                } elseif (in_array($data['status'], ['failed', 'cancelled'], true)) {
                    $instance->update(['failed_step' => $result['failed_step'] ?? 'source', 'error_code' => $data['error_code'] ?? 'operation.cancelled']);
                    if (($data['error_code'] ?? null) === self::BaselineUnavailable) {
                        // The run ended without its container stop: the copy runs until a retry or a destroy.
                        $instance->runtime()->firstOrFail()->update(['power_state' => InstanceRuntimePowerState::Running]);
                    }
                    if (($result['failed_step'] ?? null) === 'route') {
                        $route?->update(['failed_step' => 'native-create', 'error_code' => $result['error_code'] ?? 'instance.create_route_failed']);
                    }
                }
                app(ProcessInstanceOperation::class)->completeMembers($operation, OperationStatus::from(ValidatedData::string($data['status'])),
                    ValidatedData::nullableString($data['error_code'] ?? null), $result);
                $container = ValidatedData::object($input['direct_provisioning'])['container'] ?? null;
                if (is_array($container) && in_array($data['status'], ['succeeded', 'failed', 'cancelled'], true)) {
                    // A clone's Processes start in its completion, whose end records them; a create that failed only at its baseline read
                    // started none.
                    if (! $handover && ($data['error_code'] ?? null) !== self::BaselineUnavailable) {
                        app(ContainerProcesses::class)->complete($instance, $container['processes'] ?? [], $data['status'] === 'succeeded',
                            ValidatedData::nullableString($data['error_code'] ?? null));
                    }
                    if ($data['status'] === 'succeeded') {
                        // A clone installs, but does not start, a Process that is stopped on its default.
                        $result['processes_stopped'] = $instance->processes()->where('desired_state', DesiredProcessState::Stopped)
                            ->orderBy('name')->pluck('name')->all();
                    }
                }
            }
            $ended = $operation->status->terminal();
            $operation = $this->direct->project($operation, $data, $result);
            if ($handover && ! $ended) {
                // The create row is terminal and has let go of the Instance lock; the completion takes it in this same transaction, so
                // nothing takes the Instance in between. Its vault copy and its Swamp start run after the commit.
                // The admission runs in its own savepoint. A refusal, such as a Route whose Cluster lost its Router since the create's
                // admission, is recorded as a failed completion, so the create still ends and names it; any other error is recorded the
                // same way with `operation.admission_failed`, rather than leave the create to the reconciler until its budget ends.
                $complete = app(StartInstanceCompleteAction::class);
                try {
                    $completion = DB::transaction(fn (): Operation => app(OperationIdempotency::class)->suspended(
                        fn (): Operation => $complete->admit($instance, $operation->triggered_by, $operation->request_id)));
                } catch (ResourceOperationException $refusal) {
                    $completion = $complete->refused($instance, $operation, $refusal->errorCode, $refusal->getMessage());
                } catch (Throwable $error) {
                    report($error);
                    $completion = $complete->refused($instance, $operation, 'operation.admission_failed', 'The completion could not be admitted.');
                }
                app(OperationStore::class)->change($operation, ['result' => [...($operation->result ?? []), 'completion_operation_id' => $completion->id]]);
                $operation->refresh();
            }

            return $operation;
        });
    }

    /**
     * The workspace baseline of a clone whose run succeeded: the recorded one stays (a resumed create never hides the user's work), a new
     * one is read over SSH, and false means the read failed.
     *
     * @return array{heads: list<string>, stashes: list<string>, untracked: list<string>|null}|false|null
     */
    private function baseline(Operation $operation): array|false|null
    {
        $instance = Instance::query()->with('node')->findOrFail($operation->target_id);
        if ($instance->runtime()->first()?->workspace_baseline !== null) {
            return null;
        }

        return app(ContainerWorkspaceRead::class)->baseline($instance) ?? false;
    }

    /**
     * The reservation is intentional; name the existing recovery paths without claiming physical cleanup.
     *
     * @param  array<string, mixed>  $container
     * @return array<string, mixed>|null
     */
    public function recovery(Operation $operation, array $container): ?array
    {
        $input = $operation->input;
        $retained = Instance::query()->whereKey($operation->target_id)->where('id', $input['instance_id'])
            ->where('project_id', $input['project_id'])->where('node_id', $input['node_id'])->where('node_id', $operation->node_id)
            ->where('name', $input['name'])->where('source_prepare_id', $input['prepare_id'])
            ->whereIn('status', [InstanceState::Reserved, InstanceState::SourceResolved])->lockForUpdate()->first();
        if ($retained === null) {
            return null;
        }
        $clone = ($container['clone'] ?? null) !== null;

        return [
            'state' => 'retained_for_retry',
            'retry' => [
                'command' => 'instance:create',
                'project_id' => $input['project_id'], 'node_id' => $input['node_id'], 'name' => $input['name'],
                'reuse_original_options' => true, 'new_idempotency_key' => true,
            ],
            'destroy' => [
                'command' => 'instance:destroy', 'instance_id' => $operation->target_id,
                'available' => $clone, 'options' => ['yes' => true, 'force' => true],
                ...($clone ? [] : ['error_code' => 'operation.kind_unavailable', 'reason' => 'container_default_destroy_unavailable']),
            ],
        ];
    }

    /**
     * The clone's T3 server from the run's result: the environment id, version and session expiry the run checked in the container,
     * and the Gateway's session from the vault key the run named. The URL and label are the admitted ones. A result without them is
     * no proof that the server is registered.
     *
     * @param  array<string, mixed>  $t3
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function server(array $t3, array $result): array
    {
        $server = $result['t3'] ?? null;
        $session = is_array($server) ? ($server['gateway_session'] ?? null) : null;
        $environment = is_array($server) ? ($server['environment_id'] ?? null) : null;
        $expires = is_array($server) ? ($server['expires_at'] ?? null) : null;
        if (! is_array($server) || ! is_array($session) || ($session['vault'] ?? null) !== 'orbit-control-inputs'
            || ! is_string($session['key'] ?? null) || ! str_starts_with($session['key'], 'nckrtl-instance-t3-')
            || ! is_string($environment) || preg_match('/\A[A-Za-z0-9._-]{1,255}\z/D', $environment) !== 1
            || ! is_string($server['server_version'] ?? null) || ! is_string($expires) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/D', $expires) !== 1) {
            throw new ResourceOperationException('instance.result_invalid', 'The clone did not prove its registered T3 server.');
        }
        $secret = app(SwampVaultExecutionCopy::class)->readSecret('orbit-control-inputs', $session['key']);
        if ($secret === '') {
            throw new ResourceOperationException('instance.result_invalid', 'The T3 session of the clone is missing from the vault.');
        }

        return ['environment_id' => $environment, 'server_version' => $server['server_version'], 'expires_at' => $expires,
            'url' => ValidatedData::string($t3['url']), 'label' => ValidatedData::string($t3['label']), 'admin_session' => $secret];
    }
}
