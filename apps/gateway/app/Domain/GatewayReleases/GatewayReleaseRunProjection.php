<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use App\Actions\Fleet\ContinueReleaseFleetAction;
use App\Console\Scheduling\GatewaySchedule;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\Logs\LogRedactor;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Operations\DirectOperationWorkflowChoice;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Operations\NativeFailedStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseRetry;
use App\Infrastructure\GatewayReleases\GatewayReleaseSupersession;
use App\Infrastructure\GitHub\HttpGitHubApi;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Jobs\Fleet\AdmitFleetRollout;
use App\Models\GatewayRelease;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The `gateway.release` run: at its start the Gateway mints one read token for the release repository's contents and
 * Actions and puts it in the vault; at its end the run's terminal result ends the release record through the same
 * recorder the legacy runner uses, so pauses, alerts and the fleet rollout follow it unchanged.
 */
final readonly class GatewayReleaseRunProjection implements DirectOperationProjection, DirectOperationStartRefusal, DirectOperationTimeout, DirectOperationWorkflowChoice, DirectOperationWorkflowInput
{
    /** Each failed step, the release phase it ends and its error code. From Migrate on, migrations ran. */
    public const array Steps = [
        'Check the serving release' => ['verify', 'gateway.release_reconcile_not_live', false],
        'Reload serving workflows' => ['swamp', 'gateway.release_swamp_load_failed', true],
        'Verify the serving release' => ['verify', 'gateway.release_reconcile_not_live', true],
        'Check release ownership' => ['prepare', 'gateway.release_prepare_failed', false],
        'Fetch the commit' => ['prepare', 'gateway.release_prepare_failed', false],
        'Build the release' => ['prepare', 'gateway.release_prepare_failed', false],
        'Install the web build' => ['web', 'gateway.release_web_install_failed', false],
        'Clone the prepared release' => ['prepare', 'gateway.release_clone_failed', false],
        'Sleep preparation' => ['prepare', 'gateway.release_preparer_sleep_failed', false],
        'Snapshot the database' => ['snapshot', 'gateway.release_snapshot_failed', false],
        'Migrate' => ['migrate', 'gateway.release_migrate_failed', true],
        'Check the migration' => ['migrate', 'gateway.release_migrate_failed', true],
        'Wait for active Operations' => ['idle', 'gateway.release_idle_check_failed', true],
        'Check the candidate' => ['ready', 'gateway.release_candidate_unhealthy', true],
        'Load Swamp on Node 209' => ['swamp', 'gateway.release_swamp_load_failed', true],
        'Read the activation workflow' => ['handoff', 'gateway.release_handoff_failed', true],
        'Hand over a retained Gateway release' => ['handoff', 'gateway.release_handoff_failed', true],
        'Confirm the Gateway handover' => ['verify', 'gateway.release_verify_failed', true],
        'Hand over the release' => ['handoff', 'gateway.release_handoff_failed', true],
        'Release unused candidate' => ['cleanup', 'gateway.release_candidate_cleanup_failed', true],
    ];

    /** The Swamp Node's Ansible collections were installed from another requirements.yml than the release's; the release goes on. */
    public const string CollectionsDrift = 'swamp.collections_drift';

    /** Whether the load's check of the Ansible collections failed: it may fail without failing the release. */
    private static function collectionsDrift(Operation $operation): bool
    {
        $progress = $operation->state['native_progress'] ?? null;

        return array_any(is_array($progress) && is_array($progress['steps'] ?? null) ? $progress['steps'] : [], fn ($step) => is_array($step) && ($step['name'] ?? null) === 'Check the Ansible collections' && ($step['status'] ?? null) === 'failed');
    }

    public function workflow(Operation $operation): string
    {
        if (($operation->input['reconcile'] ?? false) === true) {
            return 'operation-gateway-reconcile';
        }

        return ($operation->input['target'] ?? null) === 'gateway' ? 'production-release' : 'operation-gateway-release';
    }

    public function input(Operation $operation): array
    {
        if (($operation->input['reconcile'] ?? false) === true) {
            return ['intent' => array_intersect_key($operation->input, array_flip(['sha', 'ssh', 'swamp_ssh', 'grants']))];
        }

        return ['intent' => array_intersect_key($operation->input, array_flip(['target', 'sha', 'id', 'force', 'previous', 'repository', 'manifest', 'web', 'retained',
            'horizon_unit', 'horizon_drain_seconds', 'keep', 'ssh', 'swamp_ssh']))];
    }

    public function workflowInput(Operation $operation): array
    {
        if (($operation->input['retained'] ?? false) === true || ($operation->input['reconcile'] ?? false) === true) {
            // An empty map; DirectOperation encodes it as {} for Swamp after checking it.
            return [...$this->input($operation), 'execution_secrets' => []];
        }

        $credentials = app(GitHubAppStore::class)->credentials()
            ?? throw new ResourceOperationException('operation.github_token_unavailable', 'Repository installation unavailable.', 503);
        $github = app(HttpGitHubApi::class);
        $token = $github->scopedRepositoryToken($credentials, GitHubTokenGrant::from([
            'repository' => ValidatedData::string($operation->input['repository']), 'permissions' => ['contents' => 'read', 'actions' => 'read'],
        ]));
        try {
            $copy = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'gateway-release-'.$operation->id, $token->reveal());
        } catch (Throwable $exception) {
            if (! $github->revokeInstallationToken($token)) {
                Log::warning('operation.github_token_revoke_failed');
            }
            throw $exception;
        }

        return [...$this->input($operation), 'execution_secrets' => ['github_token' => $copy]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $snapshot = ValidatedData::object($data['result'] ?? [])['snapshot'] ?? null;
        $snapshot = is_string($snapshot) && trim($snapshot) !== '' ? trim($snapshot) : null;
        $status = $data['status'] ?? null;
        $pin = null;
        if ($status === 'succeeded' && GatewayRelease::query()->whereKey($operation->target_id)
            ->where('trigger', 'deploy')->where('outcome', GatewayRelease::Running)->exists()) {
            // Resolve the pin while the running Operation still owns the unfinished release.
            $pin = app(GatewayReleaseSupersession::class)->check(ValidatedData::string($operation->input['sha']));
        }

        return DB::transaction(function () use ($operation, $data, $status, $snapshot, $pin): Operation {
            $projected = app(DirectOperation::class)->project($operation, $data, $status === 'succeeded' ? ['sha' => $operation->input['sha'],
                'previous' => $operation->input['previous'], 'snapshot' => $snapshot] : []);
            if (in_array($status, ['succeeded', 'failed', 'cancelled', 'interrupted'], true)) {
                $this->finish($operation, $data, $snapshot, $pin);
            }

            return $projected;
        });
    }

    /** A start that the Gateway refused ran nothing: the release record fails as retryable instead of staying running. */
    public function refused(Operation $operation): void
    {
        $record = GatewayRelease::query()->find($operation->target_id);
        if (! $record instanceof GatewayRelease || $record->finished()) {
            return;
        }
        app(GatewayReleaseRecorder::class)->fail($record, new GatewayReleaseException('run', $operation->error_code ?? 'gateway.release_run_failed',
            "The Swamp run of Operation {$operation->id} was refused before it started.", 503, sha: ValidatedData::string($operation->input['sha'])),
            retryable: true, durationMs: 0, phase: 'run');
    }

    /** The run may still be working on the Gateway Node, or ended with no readable result, so its release pauses with the old or new code live. */
    public function timedOut(Operation $operation): void
    {
        $timedOut = $operation->error_code === 'operation.timed_out';
        $this->finish($operation, ['status' => $timedOut ? 'timed_out' : 'failed', 'error_code' => $operation->error_code], null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{outcome: string, sha?: string, error?: string}|null  $pin
     */
    private function finish(Operation $operation, array $data, ?string $snapshot, ?array $pin = null): void
    {
        $record = GatewayRelease::query()->find($operation->target_id);
        if (! $record instanceof GatewayRelease || $record->finished()) {
            return;
        }
        $sha = ValidatedData::string($operation->input['sha']);
        $status = ValidatedData::string($data['status']);
        $phases = ['run' => ['operation_id' => $operation->id, 'status' => $status]];
        $step = NativeFailedStep::label($data, array_keys(self::Steps));
        // A run that stops at an unknown step may already have migrated. The legacy host pauses in this case.
        [$phase, $code, $mayHaveChangedState] = $status === 'succeeded' ? ['verify', null, true]
            : ($step === null ? ['run', $status === 'timed_out' ? 'gateway.release_run_timed_out' : 'gateway.release_run_failed', true] : self::Steps[$step]);
        $reconcile = ($operation->input['reconcile'] ?? false) === true;
        $container = $reconcile || ($operation->input['target'] ?? null) === 'gateway';
        $retained = $reconcile || ($operation->input['retained'] ?? false) === true;
        // The step's own refusal code, such as gateway.release_downgrade, is more exact than the step's.
        $detail = is_array($data['failure'] ?? null) && is_string($data['failure']['detail'] ?? null) ? $data['failure']['detail'] : '';
        if ($code !== null && preg_match('/\b(gateway\.release_[a-z_]+):/', $detail, $refusal) === 1) {
            $code = $refusal[1];
        }
        // The fleet rollout plans from a verified record whose verify version is the commit.
        $phases[$phase] = [...($phases[$phase] ?? []), ...($code === null ? ['outcome' => 'passed', 'status' => 'ok', 'version' => $sha] : ['outcome' => 'failed', 'error_code' => $code])];
        if (self::collectionsDrift($operation)) {
            $phases['swamp'] = [...($phases['swamp'] ?? []), 'warning' => self::CollectionsDrift, 'fix' => 'orbit node:role:add 209 swamp --converge'];
        }
        // The run put the previous release back after a failed check; it runs on the new, expanded schema.
        $previous = ValidatedData::string($operation->input['previous']);
        $back = ! $container && $code === 'gateway.release_switched_back';
        if ($container && $code === 'gateway.release_switched_back') {
            $code = 'gateway.release_verify_failed';
            $phases[$phase]['error_code'] = $code;
        }
        if ($back) {
            $code = 'gateway.release_verify_failed';
            $phases[$phase]['error_code'] = $code;
            $phases['switch_back'] = ['outcome' => 'switched_back', 'to' => $previous];
        }
        if ($container && $code !== null) {
            // Publication can precede a failed public check. Record only the revision observed in the host inventory.
            try {
                $layout = GatewayReleaseLayout::fromConfig();
                if ($layout->usesContainers()) {
                    $inventory = $layout->observe();
                    $live = $inventory->commits[$inventory->current ?? ''] ?? null;
                    if ($live !== null) {
                        $phases['serving'] = ['sha' => $live, 'observed_at' => now()->toIso8601String()];
                    }
                }
            } catch (GatewayReleaseException) {
                // An unreadable inventory is no evidence about which release serves.
            }
        }
        if ($code === null && ! $reconcile) {
            app(ContinueReleaseFleetAction::class)->preserveHostBaselines($previous);
            $phases['fleet'] = ['status' => config('fleet.rollout') ? 'pending' : 'disabled', 'nodes' => []];
        }
        if ($code === null && $record->trigger === 'deploy') {
            $phases['newest_green'] = $pin ?? ['outcome' => 'unknown'];
        }
        $recorder = app(GatewayReleaseRecorder::class);
        $recorder->write(new DeployedGatewayRelease(
            id: GatewayReleaseCommit::id($sha),
            sha: $sha,
            outcome: match (true) {
                $code === null => $reconcile ? 'reconciled' : 'verified',
                $container => 'failed',
                $back => 'switched_back',
                $mayHaveChangedState => 'paused',
                default => 'failed',
            },
            trigger: $record->trigger,
            migrationsRan: $mayHaveChangedState && ! $retained,
            previousId: $previous,
            // A pause names the copy from before this attempt's migration, or the clean one an earlier pause named.
            snapshotPath: $mayHaveChangedState && ! $retained && ! $back ? ($recorder->pausedSnapshot($sha) ?? $snapshot) : $snapshot,
            cleanupPaused: false,
            retryable: $code !== null && ! $mayHaveChangedState && app(GatewayReleaseRetry::class)->retryable($code, $sha, $record->id),
            durationMs: $record->created_at === null ? 0 : (int) $record->created_at->diffInMilliseconds(now()),
            phases: $phases,
            errorCode: $code,
            message: $code === null ? null : "Swamp run for Operation {$operation->id} ended {$status}".($step === null ? '.' : " at {$step}.").self::failureOutput($data, $phase),
        ), $record);
        if ($code === null && ! $reconcile) {
            DB::afterCommit(static function () use ($sha, $record): void {
                try {
                    GatewaySchedule::dispatch(new AdmitFleetRollout($record->id));
                } catch (Throwable) {
                    Log::warning('Could not queue the fleet work after a verified Gateway release.', ['release' => GatewayReleaseCommit::id($sha)]);
                }
            });
        }
    }

    /**
     * The migration output or pre-load guard refusal, including its blocking Operation IDs, for the release record.
     *
     * @param  array<string,mixed>  $data
     */
    private static function failureOutput(array $data, string $phase): string
    {
        $output = match ($phase) {
            'migrate' => ValidatedData::object($data['result'] ?? [])['migrate'] ?? null,
            'idle' => ValidatedData::object($data['failure'] ?? [])['detail'] ?? null,
            default => null,
        };
        if (! is_string($output) || trim($output) === '') {
            return '';
        }
        // The output can carry SQL with its values, and the message reaches the release alert: it is redacted like any native failure.
        $text = preg_replace('/\e\[[0-9;]*m|[\x00-\x08\x0b-\x1f\x7f]/u', '', mb_scrub(trim($output), 'UTF-8')) ?? '';
        $text = app(LogRedactor::class)->redact(Str::substr($text, -1500), []);

        return trim($text) === '' ? '' : "\n".trim($text);
    }
}
