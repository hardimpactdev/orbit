<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use App\Console\Scheduling\GatewaySchedule;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\Logs\LogRedactor;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationTimeout;
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
final readonly class GatewayReleaseRunProjection implements DirectOperationProjection, DirectOperationTimeout, DirectOperationWorkflowInput
{
    /** Each failed step, the release phase it ends and its error code. From Migrate on, migrations ran. */
    public const array Steps = [
        'Build the release' => ['prepare', 'gateway.release_prepare_failed', false],
        'Install the web build' => ['web', 'gateway.release_web_install_failed', false],
        'Snapshot the database' => ['snapshot', 'gateway.release_snapshot_failed', false],
        'Migrate' => ['migrate', 'gateway.release_migrate_failed', true],
        'Check the migration' => ['migrate', 'gateway.release_migrate_failed', true],
        'Wait for active Operations' => ['idle', 'gateway.release_idle_check_failed', true],
        'Load Swamp on Node 209' => ['swamp', 'gateway.release_swamp_load_failed', true],
        'Hand over the release' => ['handoff', 'gateway.release_handoff_failed', true],
    ];

    /** The Swamp Node's Ansible collections were installed from another requirements.yml than the release's; the release goes on. */
    public const string CollectionsDrift = 'swamp.collections_drift';

    /** Whether the load's check of the Ansible collections failed: it may fail without failing the release. */
    private static function collectionsDrift(Operation $operation): bool
    {
        $progress = $operation->state['native_progress'] ?? null;

        return array_any(is_array($progress) && is_array($progress['steps'] ?? null) ? $progress['steps'] : [], fn ($step) => is_array($step) && ($step['name'] ?? null) === 'Check the Ansible collections' && ($step['status'] ?? null) === 'failed');
    }

    public function input(Operation $operation): array
    {
        return ['intent' => array_intersect_key($operation->input, array_flip(['sha', 'id', 'force', 'previous', 'repository', 'manifest', 'web',
            'horizon_unit', 'horizon_drain_seconds', 'keep', 'ssh', 'swamp_ssh']))];
    }

    public function workflowInput(Operation $operation): array
    {
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
        $projected = app(DirectOperation::class)->project($operation, $data, $status === 'succeeded' ? ['sha' => $operation->input['sha'],
            'previous' => $operation->input['previous'], 'snapshot' => $snapshot] : []);
        if (in_array($status, ['succeeded', 'failed', 'cancelled', 'interrupted'], true)) {
            $this->finish($operation, $data, $snapshot);
        }

        return $projected;
    }

    /** The run may still be working on the Gateway Node, or ended with no readable result, so its release pauses with the old or new code live. */
    public function timedOut(Operation $operation): void
    {
        $timedOut = $operation->error_code === 'operation.timed_out';
        $this->finish($operation, ['status' => $timedOut ? 'timed_out' : 'failed', 'error_code' => $operation->error_code], null);
    }

    /** @param array<string, mixed> $data */
    private function finish(Operation $operation, array $data, ?string $snapshot): void
    {
        $record = GatewayRelease::query()->find($operation->target_id);
        if (! $record instanceof GatewayRelease || $record->finished()) {
            return;
        }
        $sha = ValidatedData::string($operation->input['sha']);
        $status = ValidatedData::string($data['status']);
        $phases = ['run' => ['operation_id' => $operation->id, 'status' => $status]];
        $step = NativeFailedStep::label($data, array_keys(self::Steps));
        // A cancelled, interrupted or expired run, or one that stopped at an unknown step, may have migrated: it pauses.
        [$phase, $code, $migrated] = $status === 'succeeded' ? ['verify', null, true]
            : ($step === null ? ['run', $status === 'timed_out' ? 'gateway.release_run_timed_out' : 'gateway.release_run_failed', true] : self::Steps[$step]);
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
        $back = $code === 'gateway.release_switched_back';
        if ($back) {
            $code = 'gateway.release_verify_failed';
            $phases[$phase]['error_code'] = $code;
            $phases['switch_back'] = ['outcome' => 'switched_back', 'to' => $previous];
        }
        $recorder = app(GatewayReleaseRecorder::class);
        $recorder->write(new DeployedGatewayRelease(
            id: GatewayReleaseCommit::id($sha),
            sha: $sha,
            outcome: $code === null ? 'verified' : ($back ? 'switched_back' : ($migrated ? 'paused' : 'failed')),
            trigger: $record->trigger,
            migrationsRan: $migrated,
            previousId: $previous,
            // A pause names the copy from before this attempt's migration, or the clean one an earlier pause named.
            snapshotPath: $migrated && ! $back ? ($recorder->pausedSnapshot($sha) ?? $snapshot) : $snapshot,
            cleanupPaused: false,
            retryable: $code !== null && ! $migrated && app(GatewayReleaseRetry::class)->retryable($code, $sha, $record->id),
            durationMs: $record->created_at === null ? 0 : (int) $record->created_at->diffInMilliseconds(now()),
            phases: $phases,
            errorCode: $code,
            message: $code === null ? null : "Swamp run for Operation {$operation->id} ended {$status}".($step === null ? '.' : " at {$step}.").self::failureOutput($data, $phase),
        ), $record);
        if ($code === null) {
            // The activate child admits task image refreshes after verification and the new Horizon start.
            $id = GatewayReleaseCommit::id($sha);
            DB::afterCommit(static function () use ($id): void {
                try {
                    // Stage 8: the verified release is the fleet's desired state, so its footprint rollout follows.
                    GatewaySchedule::dispatch(new AdmitFleetRollout);
                } catch (Throwable $failure) {
                    // The daily window trigger asks again; a release is never held by it.
                    Log::warning('The fleet rollout after a release was not queued.', ['release' => $id, 'message' => $failure->getMessage()]);
                }
            });
        }
        if ($code === null && $record->trigger === 'deploy') {
            // As the legacy runner: whether this manual deploy pins an older commit decides if automation pauses.
            $recorder->progress($record->refresh(), [...$record->phases, 'newest_green' => app(GatewayReleaseSupersession::class)->check($sha)]);
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
