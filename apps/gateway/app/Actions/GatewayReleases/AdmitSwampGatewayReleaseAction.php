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
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GitHubArtifactWebBuild;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\GatewayRelease;
use App\Models\Node;
use Illuminate\Support\Facades\Config;

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
        if (GatewayRelease::query()->whereIn('outcome', [GatewayRelease::Running, GatewayRelease::Queued])->exists()) {
            throw new GatewayReleaseException('deploy', 'gateway.release_in_progress', 'Another Gateway release is queued or running.', 409);
        }
        $gateway = GatewayCommandNode::find() ?? throw new GatewayReleaseException('deploy', 'gateway.release_gateway_node_missing', 'No active Gateway Node exists.', 500);
        $delivery = $this->agent->fetch($commit, $gateway);
        $web = $this->web->select($commit);
        $horizon = GatewayHorizon::find($gateway);
        $record = $this->recorder->begin('deploy', $commit, null, $force) ?? throw new GatewayReleaseException('deploy', 'gateway.release_record_failed', 'The release record cannot be written.', 500);
        $operation = $this->operations->execute('gateway.release', 'gateway_release', $record->id, self::SwampNode, [
            'sha' => $commit,
            'id' => GatewayReleaseCommit::id($commit),
            'force' => $force,
            'previous' => $previous,
            'repository' => $web['repository'],
            'manifest' => $delivery['manifest'],
            'web' => ['artifact_id' => $web['artifact_id'], 'size' => $web['size'], 'digest' => $web['digest']],
            'horizon_unit' => $horizon === null ? '' : new SystemdProcessRenderer()->unitName($horizon),
            'horizon_drain_seconds' => GatewayHorizon::longestJobSeconds() + 30,
            'keep' => max(2, Config::integer('orbit.gateway_releases.keep')),
            'ssh' => $this->ssh->forNode($gateway),
            'swamp_ssh' => $this->ssh->forNode(Node::query()->findOrFail(self::SwampNode)),
        ], 'gateway-release', null, lockKey: 'gateway-release');
        $this->recorder->progress($record, ['run' => ['operation_id' => $operation->id]], $commit);

        return $record->refresh();
    }
}
