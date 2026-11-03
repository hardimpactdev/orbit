<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Operations\NativeFailedStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseRetry;
use App\Infrastructure\GatewayReleases\GatewayReleaseSupersession;
use App\Infrastructure\GitHub\HttpGitHubApi;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\GatewayRelease;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The `gateway.release` run: at its start the Gateway mints one read token for the release repository's contents and
 * Actions and puts it in the vault; at its end the run's terminal result ends the release record through the same
 * recorder the legacy runner uses, so pauses, alerts and the fleet rollout follow it unchanged.
 */
final readonly class GatewayReleaseRunProjection implements DirectOperationProjection, DirectOperationWorkflowInput
{
    /** Each failed step, the release phase it ends and its error code. From Migrate on, migrations ran. */
    public const array Steps = [
        'Build the release' => ['prepare', 'gateway.release_prepare_failed', false],
        'Install the web build' => ['web', 'gateway.release_web_install_failed', false],
        'Snapshot the database' => ['snapshot', 'gateway.release_snapshot_failed', false],
        'Migrate' => ['migrate', 'gateway.release_migrate_failed', true],
        'Load Swamp on Node 209' => ['swamp', 'gateway.release_swamp_load_failed', true],
        'Hand over the release' => ['handoff', 'gateway.release_handoff_failed', true],
    ];

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
        $status = $data['status'] ?? null;
        $result = $status === 'succeeded' ? array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['sha', 'previous', 'snapshot'])) : [];
        $projected = app(DirectOperation::class)->project($operation, $data, $result);
        $record = GatewayRelease::query()->find($operation->target_id);
        if ($record instanceof GatewayRelease && ! $record->finished() && in_array($status, ['succeeded', 'failed', 'cancelled'], true)) {
            $this->finish($record, $operation, $data);
        }

        return $projected;
    }

    /** @param array<string, mixed> $data */
    private function finish(GatewayRelease $record, Operation $operation, array $data): void
    {
        $sha = ValidatedData::string($operation->input['sha']);
        $phases = ['run' => ['operation_id' => $operation->id, 'status' => $data['status']]];
        $step = NativeFailedStep::label($data, array_keys(self::Steps));
        [$phase, $code, $migrated] = $data['status'] === 'succeeded' ? ['verify', null, true]
            // An unknown or cancelled step may have migrated, so it pauses like a failure after migration.
            : ($step === null ? ['run', 'gateway.release_run_failed', true] : self::Steps[$step]);
        // The fleet rollout plans from a verified record whose verify version is the commit.
        $phases[$phase] = $code === null ? ['outcome' => 'passed', 'status' => 'ok', 'version' => $sha] : ['outcome' => 'failed', 'error_code' => $code];
        $snapshot = $data['status'] === 'succeeded' ? ValidatedData::object($data['result'] ?? [])['snapshot'] ?? null : null;
        $recorder = app(GatewayReleaseRecorder::class);
        $recorder->write(new DeployedGatewayRelease(
            id: GatewayReleaseCommit::id($sha),
            sha: $sha,
            outcome: $code === null ? 'verified' : ($migrated ? 'paused' : 'failed'),
            trigger: $record->trigger,
            migrationsRan: $migrated,
            previousId: ValidatedData::string($operation->input['previous']),
            snapshotPath: is_string($snapshot) ? $snapshot : null,
            cleanupPaused: false,
            retryable: $code !== null && ! $migrated && app(GatewayReleaseRetry::class)->retryable($code, $sha, $record->id),
            durationMs: $record->created_at === null ? 0 : (int) $record->created_at->diffInMilliseconds(now()),
            phases: $phases,
            errorCode: $code,
            message: $code === null ? null : "Swamp run for Operation {$operation->id} failed".($step === null ? '.' : " at {$step}."),
        ), $record);
        if ($code === null && $record->trigger === 'deploy') {
            // As the legacy runner: whether this manual deploy pins an older commit decides if automation pauses.
            $recorder->progress($record->refresh(), [...$record->phases, 'newest_green' => app(GatewayReleaseSupersession::class)->check($sha)]);
        }
    }
}
