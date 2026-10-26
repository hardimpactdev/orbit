<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Data\Fleet\DesiredFleetStateData;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeCliInstaller;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Fleet\Footprint\CaddyFootprintArtifact;
use App\Infrastructure\Nodes\Roles\NodeRoleConvergeLock;
use App\Models\Node;
use Closure;
use Throwable;

/**
 * Brings one Node to the desired fleet state (ADR 0202):
 *
 * 1. Probe SSH. A Node that does not answer is `unreachable`, and nothing runs.
 * 2. Take the Node's role lock, the lock every role converge takes. A busy Node is `deferred`.
 * 3. Install the CLI if it is missing. A CLI Orbit did not install leaves the rollout set (`skipped`).
 * 4. Call `cli.self-update`. A Gateway rollback retries once with `--allow-downgrade-to`.
 * 5. Re-apply the Gateway-rendered footprint where its digest changed, and the artifacts that repair an
 *    owned issue the baseline shows.
 * 6. Verify with Doctor and the agent's reported version.
 *
 * A step that fails is retried once when the Node still answers; when it does not, the Node is
 * `unreachable`. A step that meets a busy lock is `deferred`. Any other failure is `failed`, with the step,
 * the error code, and the evidence.
 */
final readonly class FleetNodeConverger implements FleetNodeVisitor
{
    /** Error codes of a lock another operation holds. Nothing changed, so the catch-up tries again. */
    public const array BusyCodes = [
        'node_role.node_busy',
        'node.update_busy',
        'self_update.busy',
        'agent.converge_busy',
        'agent.converge_lock_lost',
        'node.lock_lost',
        'tool.operation_locked',
    ];

    public function __construct(
        private NodeReachabilityProbe $reachability,
        private NodeRoleConvergeLock $lock,
        private NodeCliInstaller $cli,
        private AgentCommandRunner $agents,
        private NodeFootprint $footprint,
        private FleetNodeVerification $verifier,
        private AgentDelivery $delivery,
        private NodeCliState $cliState = new NodeCliState,
    ) {}

    public function converge(Node $node, DesiredFleetStateData $desired, bool $allowDowngrade = false, bool $firstVisit = false): FleetNodeResult
    {
        if (! $this->reachable($node)) {
            return $this->unreachable($node, 'ssh');
        }

        try {
            return $this->lock->run(
                $node,
                fn (): FleetNodeResult => $this->convergeLocked($node, $desired, $allowDowngrade, $firstVisit),
                errorCode: 'fleet.node_busy',
                step: 'node-lock',
            );
        } catch (NodeRoleOperationException $exception) {
            return new FleetNodeResult(
                FleetNodeOutcome::Deferred,
                step: 'node-lock',
                errorCode: $exception->underlyingErrorCode,
                message: $exception->getMessage(),
            );
        }
    }

    /** Looks at a Node left out for a foreign CLI again. Returns whether it rejoined the rollout set. */
    public function recheckCli(Node $node): bool
    {
        // The reachability probe opens a fresh connection with a short connect timeout, so an offline Node
        // costs every run only that probe, not a full command timeout.
        if (! $this->reachable($node)) {
            return false;
        }

        try {
            if ($this->cli->inspect($node) === 'foreign') {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $this->cliState->clear($node);

        return true;
    }

    private function convergeLocked(Node $node, DesiredFleetStateData $desired, bool $allowDowngrade, bool $firstVisit): FleetNodeResult
    {
        $evidence = [];
        $step = 'baseline';

        try {
            $baseline = $this->verifier->baseline($node);

            $step = 'cli';
            // `cli.install` holds the Node's update lock itself; self-update takes it in between.
            $installation = $this->retried($node, fn () => $this->cli->ensure($node, $desired->cli));
            $this->cliState->clear($node);
            $evidence['cli'] = $installation->toArray();

            $step = 'self-update';
            $update = $this->retried($node, fn () => $this->selfUpdate($node));

            if ($update['downgrade_refused'] && $allowDowngrade && $desired->cli->version !== null) {
                $update = $this->retried($node, fn () => $this->selfUpdate($node, $desired->cli->version));
            }

            if ($update['downgrade_refused']) {
                throw $update['error'] ?? new ResourceOperationException('self_update.downgrade_refused', 'orbit self-update refused a downgrade.', 502);
            }

            $evidence['self_update'] = $update['report'];

            if ($update['pending']) {
                return new FleetNodeResult(
                    FleetNodeOutcome::Waiting,
                    step: 'self-update',
                    errorCode: $update['waiting_code'],
                    message: $update['waiting_code'] === 'fleet.release_pending'
                        ? 'orbit self-update reports the CLI release as not published yet.'
                        : 'orbit self-update skipped a step that should have run.',
                    evidence: $evidence,
                );
            }

            $step = 'footprint';
            $repairs = FleetNodeVerifier::repairs($baseline);
            $footprint = $this->retried($node, fn () => $this->footprint->converge($node, $repairs === [] ? false : $repairs));
            $evidence['footprint'] = $footprint->toArray();

            // Caddy refusing the Caddyfile on the first Node of a rollout, after Orbit's Caddy code changed, is more
            // likely Orbit's own template or Caddy pin than a user's site: fail and halt before every Node gets it.
            // A refusal while only repairing live drift, such as a user's broken site, stays skipped.
            if ($firstVisit
                && ($footprint->skipped['caddy']['reason'] ?? null) === CaddyFootprintArtifact::ValidateRefused
                && ! $footprint->skipped['caddy']['repair']) {
                return $this->failed('footprint', 'node.footprint_caddy_failed', $footprint->skipped['caddy']['message'], $evidence, footprintDigest: $footprint->digest);
            }

            $step = 'verify';
            $tolerated = isset($footprint->skipped['caddy']) ? ['role.caddy_build_drift'] : [];
            $verification = $this->verifier->verify($node, $baseline, $this->delivery->reportedVersion($node), $tolerated);
            $evidence['verify'] = $verification;
        } catch (NodeUnreachable) {
            return $this->unreachable($node, $step, $evidence);
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode === 'cli.foreign_binary') {
                $this->cliState->markForeign($node);

                return new FleetNodeResult(FleetNodeOutcome::Skipped, 'cli', $exception->errorCode, $exception->getMessage(), $evidence);
            }

            if (in_array($exception->errorCode, self::BusyCodes, true)) {
                return new FleetNodeResult(FleetNodeOutcome::Deferred, $step, $exception->errorCode, $exception->getMessage(), [...$evidence, 'details' => $exception->details]);
            }

            return $this->failed($step, $exception->errorCode, $exception->getMessage(), [...$evidence, 'details' => $exception->details]);
        } catch (Throwable $exception) {
            return $this->failed($step, 'fleet.node_failed', $exception->getMessage(), $evidence);
        }

        if (! $verification['passed']) {
            return $this->failed('verify', 'fleet.verify_failed', $this->verifyMessage($verification), $evidence, $verification['agent_version'], $footprint->digest);
        }

        $changed = $installation->changed() || $update['changed'] || $footprint->changed();

        return new FleetNodeResult(
            $changed ? FleetNodeOutcome::Converged : FleetNodeOutcome::Unchanged,
            evidence: $evidence,
            cliVersion: $update['cli_version'] ?? $installation->version ?? $desired->cli->version,
            agentVersion: $verification['agent_version'] ?? $this->delivery->reportedVersion($node),
            footprintDigest: $footprint->digest,
        );
    }

    /**
     * Runs a step, and runs it once more when it fails while the Node still answers SSH: a dropped channel
     * or a timeout is usually transient. A Node that no longer answers is unreachable, not failed. A busy
     * lock and a foreign CLI are not retried.
     *
     * @template T
     *
     * @param  Closure(): T  $step
     * @return T
     *
     * @throws NodeUnreachable
     */
    private function retried(Node $node, Closure $step): mixed
    {
        try {
            return $step();
        } catch (Throwable $exception) {
            if ($exception instanceof ResourceOperationException && (in_array($exception->errorCode, self::BusyCodes, true) || $exception->errorCode === 'cli.foreign_binary' || ($exception->details['retry'] ?? 'yes') === 'no')) {
                throw $exception;
            }

            if (! $this->reachable($node)) {
                throw new NodeUnreachable;
            }

            return $step();
        }
    }

    private function reachable(Node $node): bool
    {
        return $this->reachability->degradation($node) !== ExporterDegradationReason::Unreachable;
    }

    /** @param array<string, mixed> $evidence */
    private function unreachable(Node $node, string $step, array $evidence = []): FleetNodeResult
    {
        return new FleetNodeResult(
            FleetNodeOutcome::Unreachable,
            step: $step,
            errorCode: 'node.ssh_unreachable',
            message: "SSH to node [{$node->name}] did not connect.",
            evidence: $evidence,
        );
    }

    /**
     * Runs self-update and reads its JSON report: the top-level `outcome` is `updated`, `unchanged`, `pending`,
     * `incomplete`, or `failed`. `pending` (the CLI release is not published yet) and `incomplete` (a step that
     * should have run was skipped) leave the Node waiting for the catch-up; neither is converged. A failed run
     * exits 1 with the failed step's error, or with the normal error envelope before any step.
     *
     * A refused downgrade comes back as `downgrade_refused`, so the caller can decide whether the Gateway
     * rolled back. A definite answer from self-update is not retried; only a run without a JSON answer is.
     *
     * @return array{changed: bool, pending: bool, waiting_code: ?string, cli_version: ?string, report: mixed, downgrade_refused: bool, error: ?ResourceOperationException}
     */
    private function selfUpdate(Node $node, ?string $downgradeTo = null): array
    {
        try {
            $result = $this->agents->run($node, 'cli.self-update', ['user' => $node->user, 'downgrade_to' => $downgradeTo]);
        } catch (AgentCommandRefused $error) {
            $code = $error->errorCode === 'cli.self_update_unavailable' && ($error->details['state'] ?? null) === 'foreign'
                ? 'cli.foreign_binary' : 'fleet.self_update_failed';
            throw new ResourceOperationException($code, $error->getMessage(), 502, $error, details: ['retry' => 'no']);
        }
        $outcome = $result['outcome'] ?? null;
        $report = $result['report'] ?? null;
        if (! in_array($outcome, ['updated', 'unchanged', 'pending', 'incomplete', 'failed'], true)
            || ! is_int($result['exit_code'] ?? null) || ! is_string($result['stdout'] ?? null) || ! is_string($result['stderr'] ?? null)
            || ($report !== null && ! is_array($report))) {
            throw new ResourceOperationException('fleet.self_update_failed', 'The agent returned an invalid self-update result.', 502);
        }

        if (in_array($outcome, ['pending', 'incomplete'], true)) {
            return [
                'changed' => false,
                'pending' => true,
                'waiting_code' => $outcome === 'pending' ? 'fleet.release_pending' : 'fleet.self_update_incomplete',
                'cli_version' => null,
                'report' => $report,
                'downgrade_refused' => false,
                'error' => null,
            ];
        }

        if ($outcome === 'failed') {
            $code = is_string($result['error_code'] ?? null) ? $result['error_code'] : 'fleet.self_update_failed';
            $error = new ResourceOperationException(
                $code,
                "orbit self-update failed on node [{$node->name}] with exit code {$result['exit_code']}.",
                502,
                details: [
                    'exit_code' => $result['exit_code'],
                    'stdout' => $result['stdout'],
                    'stderr' => $result['stderr'],
                    // A JSON answer is self-update's verdict; only a run without one, such as a dropped SSH
                    // channel (exit 255), is worth a retry.
                    'retry' => is_array($report) ? 'no' : 'yes',
                ],
            );

            if ($code === 'self_update.downgrade_refused') {
                return ['changed' => false, 'pending' => false, 'waiting_code' => null, 'cli_version' => null, 'report' => $report, 'downgrade_refused' => true, 'error' => $error];
            }

            throw $error;
        }

        return [
            'changed' => $outcome === 'updated',
            'pending' => false,
            'waiting_code' => null,
            'cli_version' => is_string($result['cli_version'] ?? null) ? $result['cli_version'] : null,
            'report' => $report,
            'downgrade_refused' => false,
            'error' => null,
        ];
    }

    /** @param array{new_issues: list<array<string, mixed>>, presence: string, agent_version: ?string} $verification */
    private function verifyMessage(array $verification): string
    {
        $codes = array_values(array_unique(array_map(static fn (array $issue): string => is_string($issue['code'] ?? null) ? $issue['code'] : 'unknown', $verification['new_issues'])));

        if ($codes !== []) {
            return 'Doctor reports '.implode(', ', $codes).' after the rollout.';
        }

        return 'The agent reports version '.($verification['agent_version'] ?? 'none').', not the pinned version.';
    }

    /** @param array<string, mixed> $evidence */
    private function failed(string $step, string $errorCode, string $message, array $evidence, ?string $agentVersion = null, ?string $footprintDigest = null): FleetNodeResult
    {
        return new FleetNodeResult(
            FleetNodeOutcome::Failed,
            step: $step,
            errorCode: $errorCode,
            message: $message,
            evidence: $evidence,
            agentVersion: $agentVersion,
            footprintDigest: $footprintDigest,
        );
    }
}
