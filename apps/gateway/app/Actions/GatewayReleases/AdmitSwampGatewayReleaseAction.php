<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Actions\Fleet\AdmitFleetRolloutAction;
use App\Actions\Fleet\ContinueReleaseFleetAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Fleet\FleetReboot;
use App\Domain\GatewayReleases\GatewayReleaseAgent;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Nodes\SwampNode;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Gateway\GatewayCommandNode;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseRetry;
use App\Infrastructure\GatewayReleases\GatewayReleaseSupersession;
use App\Infrastructure\GatewayReleases\GitHubArtifactWebBuild;
use App\Infrastructure\GatewayReleases\SwampGrantRenderer;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\Operation;
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
     * A containerized Gateway returns to its stopped old release; the unmoved host releases a retained worktree.
     */
    public function rollback(string $release): GatewayRelease
    {
        $id = GatewayReleaseCommit::assertId($release);
        if ($this->layout->usesContainers()) {
            $inventory = $this->layout->observe();
            if ($inventory->current === $id) {
                throw new GatewayReleaseException('rollback', 'gateway.release_already_current', "Release [{$id}] is already current.", 422);
            }
            $old = $inventory->commits[$id] ?? throw new GatewayReleaseException('rollback', 'gateway.release_not_prepared',
                "Release [{$id}] is not the stopped old release of this Gateway.", 422);

            return $this->execute($old, force: true, trigger: 'rollback');
        }
        $sha = $this->layout->preparedCommit($id)
            ?? throw new GatewayReleaseException('rollback', 'gateway.release_not_prepared', "Release [{$id}] is not a retained release.", 422);
        if ($this->layout->currentReleaseId() === $id) {
            throw new GatewayReleaseException('rollback', 'gateway.release_already_current', "Release [{$id}] is already current.", 422);
        }

        return $this->execute($sha, force: true, trigger: 'rollback');
    }

    public function execute(string $commit, bool $force = false, string $trigger = 'deploy', ?string $expectedServingSha = null): GatewayRelease
    {
        if (! GatewayReleaseCommit::isSha($commit)) {
            throw new GatewayReleaseException('deploy', 'gateway.release_commit_invalid', 'A Swamp release needs the full commit SHA.', 422);
        }
        // A fleet reboot pass may reboot the Gateway Node; a release waits for it as for another release (ADR 0253).
        if (FleetReboot::open() instanceof Operation) {
            throw new GatewayReleaseException('deploy', 'gateway.release_in_progress', 'A fleet reboot pass is running.', 409);
        }
        $inventory = $this->layout->observe();
        $previous = $inventory->current;
        $container = $this->layout->usesContainers();
        // A container rollback selects only the stopped old release. Any other older commit is a forward-only deploy.
        $rollback = $container && $trigger === 'rollback';
        if ($rollback && ($previous === GatewayReleaseCommit::id($commit) || ! in_array($commit, $inventory->commits, true))) {
            throw new GatewayReleaseException('rollback', 'gateway.release_not_prepared', 'Only the stopped old release of this Gateway can be restored.', 422);
        }
        if ($container && ! $rollback && ($force ||
            ($trigger !== 'reconcile' && in_array($commit, $inventory->commits, true) && $previous !== GatewayReleaseCommit::id($commit)))) {
            throw $this->forwardOnly();
        }
        if ($previous === null) {
            throw new GatewayReleaseException('deploy', 'gateway.release_not_adopted', 'The Gateway is not running from a release. Run gateway:release:adopt first.');
        }
        if ($trigger === 'auto' && GatewayReleaseCommit::id($commit) === $previous) {
            throw new GatewayReleaseException('deploy', 'gateway.release_in_progress', 'The automatic release candidate already serves.');
        }
        $gateway = GatewayCommandNode::find() ?? throw new GatewayReleaseException('deploy', 'gateway.release_gateway_node_missing', 'No active Gateway Node exists.', 500);
        $reconcile = $trigger === 'reconcile';
        if ($reconcile && (! $container || ($inventory->commits[$previous] ?? null) !== $commit)) {
            throw new GatewayReleaseException('deploy', 'gateway.release_reconcile_not_live', 'Reconciliation requires the full commit of the serving Gateway container.', 409);
        }
        if (! $reconcile && $container && $previous === GatewayReleaseCommit::id($commit)) {
            throw new GatewayReleaseException('deploy', 'gateway.release_already_current', 'This release already serves traffic.', 409);
        }

        if ($rollback) {
            // Old keeps its sealed build, web assets and agent manifest. Nothing is fetched or published.
            $publication = ['retained' => true];
        } elseif ($reconcile) {
            $checkout = $this->layout->currentPath();
            if (trim((string) @file_get_contents($checkout.'/REVISION')) !== $commit) {
                throw new GatewayReleaseException('deploy', 'gateway.release_reconcile_not_live', 'This Gateway process is running another commit.', 409);
            }
            $publication = ['reconcile' => true, 'grants' => app(SwampGrantRenderer::class)->render($checkout.'/swamp')];
        } else {
            $delivery = $this->agent->fetch($commit, $gateway);
            $web = $this->web->select($commit);
            $publication = [
                'manifest' => $delivery['manifest'],
                'repository' => $web['repository'],
                'web' => ['artifact_id' => $web['artifact_id'], 'size' => $web['size'], 'digest' => $web['digest']],
            ];
        }
        $horizon = GatewayHorizon::releaseUnit($gateway, $this->layout->usesContainers());

        // The lock keeps the release unit and a second admission out until the run owns its record.
        return $this->lock->run(function () use ($commit, $force, $trigger, $previous, $publication, $horizon, $gateway, $container, $expectedServingSha): GatewayRelease {
            // An automatic record that waits for its commit's publication is superseded by begin().
            if ($trigger === 'auto') {
                $this->assertAutomaticState($expectedServingSha, $commit);
            } else {
                $this->assertReleaseIdle();
            }
            // Admission must still have the same serving release after external publication checks.
            if ($this->layout->usesContainers() !== $container || $this->layout->currentReleaseId() !== $previous || ($trigger === 'reconcile'
                && (! $this->layout->usesContainers() || $this->layout->preparedCommit($previous) !== $commit))
                || ($container && $trigger === 'rollback' && $this->layout->preparedCommit(GatewayReleaseCommit::id($commit)) !== $commit)) {
                throw new GatewayReleaseException('deploy', 'gateway.release_in_progress', 'The current release changed during admission.', 409);
            }

            return $this->admit($commit, $force, $trigger, $previous, $publication, $horizon, $gateway);
        });
    }

    private function forwardOnly(): GatewayReleaseException
    {
        return new GatewayReleaseException('deploy', 'gateway.release_downgrade', 'The containerized Gateway deploys newer commits only. Roll back to the stopped old release, deploy a corrective commit, or reconcile the serving revision.', 409);
    }

    /** Call under the release lock, before storing either an admission or its preflight refusal. */
    public function assertAutomaticState(?string $expectedServingSha, string $candidate): void
    {
        $this->assertReleaseIdle();
        $automation = app(GatewayReleaseAutomation::class);
        $inventory = $this->layout->observe();
        $serving = $inventory->current === null ? null : ($inventory->commits[$inventory->current] ?? null);
        if ($expectedServingSha === null || $serving !== $expectedServingSha
            || ! $automation->enabled() || $automation->pause() !== null
            || GatewayReleaseSupersession::manualPin(GatewayReleaseCommit::id($expectedServingSha), $automation->resumedAfter()) !== null
            || FleetReboot::open() !== null || AdmitFleetRolloutAction::open()
            || app(ContinueReleaseFleetAction::class)->pending($expectedServingSha) !== null
            || in_array($candidate, GatewayRelease::failedShas(), true) || app(GatewayReleaseRetry::class)->automaticBackingOff($candidate)) {
            throw new GatewayReleaseException('deploy', 'gateway.release_in_progress', 'The serving release or its automatic release state changed during admission.');
        }
    }

    private function assertReleaseIdle(): void
    {
        if (GatewayRelease::query()->whereIn('outcome', [GatewayRelease::Running, GatewayRelease::Queued])->get()
            ->contains(static fn (GatewayRelease $record): bool => ! $record->awaitingPublication())) {
            throw new GatewayReleaseException('deploy', 'gateway.release_in_progress', 'Another Gateway release is queued or running.', 409);
        }
    }

    /**
     * @param  array{repository?: string, reconcile?: true, retained?: true, grants?: array<string, string>, manifest?: string, web?: array{artifact_id: int, size: int, digest: string}}  $publication
     */
    private function admit(string $commit, bool $force, string $trigger, string $previous, array $publication, string $horizon, Node $gateway): GatewayRelease
    {
        $record = $this->recorder->begin($trigger, $commit, null, $force) ?? throw new GatewayReleaseException('deploy', 'gateway.release_record_failed', 'The release record cannot be written.', 500);
        try {
            $operation = $this->start($record, $commit, $force, $previous, $publication, $horizon, $gateway);
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
     * @param  array{repository?: string, reconcile?: true, retained?: true, grants?: array<string, string>, manifest?: string, web?: array{artifact_id: int, size: int, digest: string}}  $publication
     */
    private function start(GatewayRelease $record, string $commit, bool $force, string $previous, array $publication, string $horizon, Node $gateway): Operation
    {
        return $this->operations->execute('gateway.release', 'gateway_release', $record->id, SwampNode::id(), [
            'sha' => $commit,
            ...($this->layout->usesContainers() ? ['target' => 'gateway'] : []),
            'id' => GatewayReleaseCommit::id($commit),
            'force' => $force,
            'previous' => $previous,
            ...$publication,
            'horizon_unit' => $horizon,
            'horizon_drain_seconds' => GatewayHorizon::drainSeconds(),
            'keep' => max(2, Config::integer('orbit.gateway_releases.keep')),
            'ssh' => $this->ssh->forNode($gateway),
            'swamp_ssh' => $this->ssh->forNode(Node::query()->findOrFail(SwampNode::id())),
        ], 'gateway-release', null, lockKey: 'gateway-release');
    }
}
