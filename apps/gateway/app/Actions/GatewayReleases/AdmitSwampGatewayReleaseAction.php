<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Actions\Operations\StartOperationAction;
use App\Domain\GatewayReleases\GatewayReleaseAgent;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Nodes\SwampNode;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Gateway\GatewayCommandNode;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GitHubArtifactWebBuild;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Admits one Gateway release, manual, automatic or a rollback, as the `gateway.release` Swamp run. The Gateway is
 * the trust anchor: it verifies the commit's agent pin and selects its web build here, and freezes them in the intent.
 * Swamp builds, migrates, loads and switches on the Gateway Node; the run's terminal result ends the release record.
 */
final readonly class AdmitSwampGatewayReleaseAction
{
    /** The single Swamp Node, which runs the release and serves the load. */
    public function __construct(
        private GatewayReleaseLayout $layout,
        private GatewayReleaseAgent $agent,
        private GitHubArtifactWebBuild $web,
        private GatewayReleaseRecorder $recorder,
        private StartOperationAction $operations,
        private SwampSshExecutionInput $ssh,
        private GatewayReleaseLock $lock,
    ) {}

    /**
     * A rollback is a forced release of a retained release's commit: the build reuses the sealed release, and the
     * load brings back that commit's workflows. Migrations are expand and contract, so it runs on the current schema.
     */
    public function rollback(string $release): GatewayRelease
    {
        $id = GatewayReleaseCommit::assertId($release);
        $sha = $this->layout->preparedCommit($id)
            ?? throw new GatewayReleaseException('rollback', 'gateway.release_not_prepared', "Release [{$id}] is not a retained release.", 422);
        if ($this->layout->currentReleaseId() === $id) {
            throw new GatewayReleaseException('rollback', 'gateway.release_already_current', "Release [{$id}] is already current.", 422);
        }

        return $this->execute($sha, force: true, trigger: 'rollback');
    }

    public function execute(string $commit, bool $force = false, string $trigger = 'deploy'): GatewayRelease
    {
        if (! GatewayReleaseCommit::isSha($commit)) {
            throw new GatewayReleaseException('deploy', 'gateway.release_commit_invalid', 'A Swamp release needs the full commit SHA.', 422);
        }
        $previous = $this->layout->isAdopted() ? $this->layout->currentReleaseId() : null;
        if ($previous === null) {
            throw new GatewayReleaseException('deploy', 'gateway.release_not_adopted', 'The Gateway is not running from a release. Run gateway:release:adopt first.');
        }
        $gateway = GatewayCommandNode::find() ?? throw new GatewayReleaseException('deploy', 'gateway.release_gateway_node_missing', 'No active Gateway Node exists.', 500);
        $delivery = $this->agent->fetch($commit, $gateway);
        $web = $this->web->select($commit);
        $horizon = GatewayHorizon::find($gateway);

        // The lock keeps the release unit and a second admission out until the run owns its record.
        return $this->lock->run(function () use ($commit, $force, $trigger, $previous, $delivery, $web, $horizon, $gateway): GatewayRelease {
            // An automatic record that waits for its commit's publication is superseded by begin().
            if (GatewayRelease::query()->whereIn('outcome', [GatewayRelease::Running, GatewayRelease::Queued])->get()
                ->contains(static fn (GatewayRelease $record): bool => ! $record->awaitingPublication())) {
                throw new GatewayReleaseException('deploy', 'gateway.release_in_progress', 'Another Gateway release is queued or running.', 409);
            }
            // A release that went live during the checks above is the one a failed check switches back to.
            if ($this->layout->currentReleaseId() !== $previous) {
                throw new GatewayReleaseException('deploy', 'gateway.release_in_progress', 'The current release changed during admission.', 409);
            }

            return $this->admit($commit, $force, $trigger, $previous, $delivery['manifest'], $web, $horizon, $gateway);
        });
    }

    /**
     * @param  array{repository: string, artifact_id: int, size: int, digest: string}  $web
     */
    private function admit(string $commit, bool $force, string $trigger, string $previous, string $manifest, array $web, ?Process $horizon, Node $gateway): GatewayRelease
    {
        $record = $this->recorder->begin($trigger, $commit, null, $force) ?? throw new GatewayReleaseException('deploy', 'gateway.release_record_failed', 'The release record cannot be written.', 500);
        try {
            $operation = $this->start($record, $commit, $force, $previous, $manifest, $web, $horizon, $gateway);
        } catch (Throwable $exception) {
            // Swamp may run a start it did not confirm: the record stays running under its Operation, which the shared
            // observer settles.
            $uncertain = Operation::query()->where('kind', 'gateway.release')->where('target_id', $record->id)->latest('id')->first();
            if ($exception instanceof ResourceOperationException && $exception->errorCode === 'swamp.start_uncertain'
                && $uncertain instanceof Operation && ! $uncertain->status->terminal()) {
                $this->recorder->progress($record, ['run' => ['operation_id' => $uncertain->id]], $commit);

                throw $exception;
            }
            // A run that never started changed nothing; its record must not hold the release unit back.
            $this->recorder->fail($record, $exception, retryable: true, durationMs: 0, phase: 'run');

            throw $exception;
        }
        $this->recorder->progress($record, ['run' => ['operation_id' => $operation->id]], $commit);
        if ($operation->status->terminal()) {
            $this->recorder->fail($record, new GatewayReleaseException('run', $operation->error_code ?? 'gateway.release_run_failed',
                "The Swamp run of Operation {$operation->id} did not start.", 503, sha: $commit), retryable: true, durationMs: 0, phase: 'run');
        }

        return $record->refresh();
    }

    /**
     * @param  array{repository: string, artifact_id: int, size: int, digest: string}  $web
     */
    private function start(GatewayRelease $record, string $commit, bool $force, string $previous, string $manifest, array $web, ?Process $horizon, Node $gateway): Operation
    {
        return $this->operations->execute('gateway.release', 'gateway_release', $record->id, SwampNode::id(), [
            'sha' => $commit,
            'id' => GatewayReleaseCommit::id($commit),
            'force' => $force,
            'previous' => $previous,
            'repository' => $web['repository'],
            'manifest' => $manifest,
            'web' => ['artifact_id' => $web['artifact_id'], 'size' => $web['size'], 'digest' => $web['digest']],
            'horizon_unit' => $horizon === null ? '' : new SystemdProcessRenderer()->unitName($horizon),
            'horizon_drain_seconds' => GatewayHorizon::drainSeconds(),
            'keep' => max(2, Config::integer('orbit.gateway_releases.keep')),
            'ssh' => $this->ssh->forNode($gateway),
            'swamp_ssh' => $this->ssh->forNode(Node::query()->findOrFail(SwampNode::id())),
        ], 'gateway-release', null, lockKey: 'gateway-release');
    }
}
