<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Actions\Operations\StartOperationAction;
use App\Domain\GatewayReleases\GatewayReleaseAgent;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseHold;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
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
 * Admits one Gateway release as the `gateway.release` Swamp run (`gateway:release:deploy --swamp`). The Gateway is
 * the trust anchor: it verifies the commit's agent pin and selects its web build here, and freezes them in the intent.
 * Swamp builds, migrates, loads and switches on the Gateway Node; the run's terminal result ends the release record.
 */
final readonly class AdmitSwampGatewayReleaseAction
{
    /** The single Swamp Node, which runs the release and serves the load. */
    public const int SwampNode = 209;

    public function __construct(
        private GatewayReleaseLayout $layout,
        private GatewayReleaseAgent $agent,
        private GitHubArtifactWebBuild $web,
        private GatewayReleaseRecorder $recorder,
        private StartOperationAction $operations,
        private SwampSshExecutionInput $ssh,
        private GatewayReleaseLock $lock,
    ) {}

    public function execute(string $commit, bool $force = false): GatewayRelease
    {
        if (! GatewayReleaseCommit::isSha($commit)) {
            throw new GatewayReleaseException('deploy', 'gateway.release_commit_invalid', 'A Swamp release needs the full commit SHA.', 422);
        }
        $previous = $this->layout->isAdopted() ? $this->layout->currentReleaseId() : null;
        if ($previous === null) {
            throw new GatewayReleaseException('deploy', 'gateway.release_not_adopted', 'The Gateway is not running from a release. Run gateway:release:adopt first.');
        }
        GatewayReleaseHold::assertNone();
        $gateway = GatewayCommandNode::find() ?? throw new GatewayReleaseException('deploy', 'gateway.release_gateway_node_missing', 'No active Gateway Node exists.', 500);
        $delivery = $this->agent->fetch($commit, $gateway);
        $web = $this->web->select($commit);
        $horizon = GatewayHorizon::find($gateway);

        // The lock keeps the release unit and a second admission out until the run owns its record.
        return $this->lock->run(function () use ($commit, $force, $previous, $delivery, $web, $horizon, $gateway): GatewayRelease {
            // An automatic record that waits for its commit's publication is superseded by begin().
            if (GatewayRelease::query()->whereIn('outcome', [GatewayRelease::Running, GatewayRelease::Queued])->get()
                ->contains(static fn (GatewayRelease $record): bool => ! $record->awaitingPublication())) {
                throw new GatewayReleaseException('deploy', 'gateway.release_in_progress', 'Another Gateway release is queued or running.', 409);
            }

            return $this->admit($commit, $force, $previous, $delivery['manifest'], $web, $horizon, $gateway);
        });
    }

    /**
     * @param  array{repository: string, artifact_id: int, size: int, digest: string}  $web
     */
    private function admit(string $commit, bool $force, string $previous, string $manifest, array $web, ?Process $horizon, Node $gateway): GatewayRelease
    {
        $record = $this->recorder->begin('deploy', $commit, null, $force) ?? throw new GatewayReleaseException('deploy', 'gateway.release_record_failed', 'The release record cannot be written.', 500);
        try {
            $operation = $this->start($record, $commit, $force, $previous, $manifest, $web, $horizon, $gateway);
        } catch (Throwable $exception) {
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
        return $this->operations->execute('gateway.release', 'gateway_release', $record->id, self::SwampNode, [
            'sha' => $commit,
            'id' => GatewayReleaseCommit::id($commit),
            'force' => $force,
            'previous' => $previous,
            'repository' => $web['repository'],
            'manifest' => $manifest,
            'web' => ['artifact_id' => $web['artifact_id'], 'size' => $web['size'], 'digest' => $web['digest']],
            'horizon_unit' => $horizon === null ? '' : new SystemdProcessRenderer()->unitName($horizon),
            'horizon_drain_seconds' => GatewayHorizon::longestJobSeconds() + 30,
            'keep' => max(2, Config::integer('orbit.gateway_releases.keep')),
            'ssh' => $this->ssh->forNode($gateway),
            'swamp_ssh' => $this->ssh->forNode(Node::query()->findOrFail(self::SwampNode)),
        ], 'gateway-release', null, lockKey: 'gateway-release');
    }
}
