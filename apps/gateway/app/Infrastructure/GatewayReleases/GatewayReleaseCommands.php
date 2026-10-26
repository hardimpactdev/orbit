<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Gateway\GatewayCommandNode;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Node;

/** Release intent and error mapping; the agent owns the release mutations on the Gateway Node. */
final readonly class GatewayReleaseCommands
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function switch(GatewayReleaseLayout $layout, string $id): ?string
    {
        GatewayReleaseCommit::assertId($id);
        $this->drainHorizon();
        $result = $this->run($layout, 'gateway.switch-release', ['release' => $id], 'switch', 'gateway.release_switch_failed');
        if (! array_key_exists('previous', $result) || ($result['previous'] !== null && (! is_string($result['previous']) || ! GatewayReleaseCommit::isId($result['previous'])))) {
            throw $this->malformed('switch', 'gateway.release_switch_failed');
        }

        return $result['previous'];
    }

    /** No serving-link change may publish work to a source-generation consumer. */
    private function drainHorizon(): void
    {
        $gateway = GatewayCommandNode::find();
        if (! $gateway instanceof Node) {
            throw new GatewayReleaseException('switch', 'gateway.release_gateway_node_missing', 'No active Gateway Node exists for release commands.', 500);
        }
        $process = GatewayHorizon::find($gateway);
        if ($process === null) {
            return;
        }
        $result = $this->run(null, 'gateway.drain-horizon', [
            'process_id' => $process->id,
            'name' => new SystemdProcessRenderer()->unitName($process),
            'drain_seconds' => GatewayHorizon::longestJobSeconds() + 30,
        ], 'switch', 'gateway.release_horizon_not_drained');
        if (($result['drained'] ?? null) !== true) {
            throw $this->malformed('switch', 'gateway.release_horizon_not_drained');
        }
    }

    /** @return array{source: string|null, source_sha: string|null} */
    public function forward(GatewayReleaseLayout $layout, ?string $sha, bool $force): array
    {
        $result = $this->run($layout, 'gateway.check-release-forward', ['sha' => $sha, 'force' => $force], 'guard', 'gateway.release_guard_failed');
        if (! array_key_exists('source', $result) || ! array_key_exists('source_sha', $result)
            || ($result['source'] !== null && (! is_string($result['source']) || ! GatewayReleaseCommit::isId($result['source'])))
            || ($result['source_sha'] !== null && (! is_string($result['source_sha']) || ! GatewayReleaseCommit::isSha($result['source_sha']) || $result['source'] === null || ! str_starts_with($result['source_sha'], $result['source'])))) {
            throw $this->malformed('guard', 'gateway.release_guard_failed');
        }

        return ['source' => $result['source'], 'source_sha' => $result['source_sha']];
    }

    public function origin(GatewayReleaseLayout $layout): string
    {
        $result = $this->run($layout, 'gateway.inspect-release-source', [], 'resolve', 'gateway.release_source_unknown');
        if (! is_string($result['origin'] ?? null)) {
            throw $this->malformed('resolve', 'gateway.release_source_unknown');
        }

        return $result['origin'];
    }

    /** @return array{sha: string|null, origin: string|null} */
    public function inspectPreparation(GatewayReleaseLayout $layout, string $revision, int $minimumFreeBytes, int $reservedBytes): array
    {
        $result = $this->run($layout, 'gateway.inspect-release-preparation', [
            'revision' => $revision, 'minimum_free_bytes' => $minimumFreeBytes, 'reserved_bytes' => $reservedBytes,
        ], 'layout', 'gateway.release_observation_failed');
        if (! array_key_exists('sha', $result) || ! array_key_exists('origin', $result)
            || ($result['origin'] !== null && ! is_string($result['origin']))
            || ($result['sha'] !== null && (! is_string($result['sha']) || ! GatewayReleaseCommit::isSha($result['sha']) || $result['sha'] !== $revision || $result['origin'] !== null))
            || ($result['sha'] === null && (! is_string($result['origin']) || $result['origin'] === ''))) {
            throw $this->malformed('layout', 'gateway.release_observation_failed');
        }

        return ['sha' => $result['sha'], 'origin' => $result['origin']];
    }

    /** @return array{sha: string, reused: bool} */
    public function prepare(GatewayReleaseLayout $layout, string $revision, int $minimumFreeBytes, int $reservedBytes, ?GitReadEnvironment $read, string $stepLock): array
    {
        $result = $this->run($layout, 'gateway.prepare-release', [
            'revision' => $revision, 'minimum_free_bytes' => $minimumFreeBytes, 'reserved_bytes' => $reservedBytes,
            'git_read' => $read, 'step_lock' => $stepLock,
        ], 'worktree', 'gateway.release_worktree_failed');
        if (! is_string($result['sha'] ?? null) || ! GatewayReleaseCommit::isSha($result['sha']) || ! str_starts_with($result['sha'], $revision) || ! is_bool($result['reused'] ?? null)) {
            throw $this->malformed('worktree', 'gateway.release_worktree_failed');
        }

        return ['sha' => $result['sha'], 'reused' => $result['reused']];
    }

    public function finish(GatewayReleaseLayout $layout, string $sha, string $stepLock): void
    {
        $this->changed($layout, 'gateway.finish-release', ['sha' => $sha, 'step_lock' => $stepLock], 'revision', 'gateway.release_revision_failed');
    }

    public function refresh(GatewayReleaseLayout $layout, string $id, string $stepLock): void
    {
        $this->changed($layout, 'gateway.refresh-release-configuration', ['release' => GatewayReleaseCommit::assertId($id), 'step_lock' => $stepLock], 'configuration', 'gateway.release_configuration_failed');
    }

    public function remove(GatewayReleaseLayout $layout, string $id, string $stepLock): void
    {
        $this->changed($layout, 'gateway.remove-release', ['release' => GatewayReleaseCommit::assertId($id), 'step_lock' => $stepLock], 'prune', 'gateway.release_worktree_failed');
    }

    /** @return array{installed: bool, archive_limit: int} */
    public function inspectWeb(string $webRoot, string $id): array
    {
        $result = $this->run(null, 'gateway.inspect-web-build', ['web_root' => $webRoot, 'release' => $id], 'web', 'gateway.release_web_build_invalid');
        if (! is_bool($result['installed'] ?? null) || ! is_int($result['archive_limit'] ?? null) || $result['archive_limit'] < 1) {
            throw $this->malformed('web', 'gateway.release_web_build_invalid');
        }

        return ['installed' => $result['installed'], 'archive_limit' => $result['archive_limit']];
    }

    public function installWeb(string $webRoot, string $sha, string $archive, string $digest): bool
    {
        $result = $this->run(null, 'gateway.install-web-build', ['web_root' => $webRoot, 'sha' => $sha, 'archive' => $archive, 'sha256' => $digest], 'web', 'gateway.release_web_install_failed');
        if (! is_bool($result['reused'] ?? null)) {
            throw $this->malformed('web', 'gateway.release_web_install_failed');
        }

        return $result['reused'];
    }

    public function publishWeb(string $webRoot, string $id): ?string
    {
        $result = $this->run(null, 'gateway.publish-web-build', ['web_root' => $webRoot, 'release' => $id], 'web', 'gateway.release_web_publish_failed');
        if (! array_key_exists('previous', $result) || ($result['previous'] !== null && ! is_string($result['previous']))) {
            throw $this->malformed('web', 'gateway.release_web_publish_failed');
        }

        return $result['previous'];
    }

    public function restoreWeb(string $webRoot, string $id, ?string $previous): void
    {
        $this->changed(null, 'gateway.restore-web-build', ['web_root' => $webRoot, 'release' => $id, 'previous' => $previous], 'web', 'gateway.release_web_publish_failed');
    }

    public function removeWeb(string $webRoot, string $id): void
    {
        $this->changed(null, 'gateway.remove-web-build', ['web_root' => $webRoot, 'release' => $id], 'web', 'gateway.release_web_install_failed');
    }

    /** @param list<string> $retained */
    public function pruneWeb(string $webRoot, array $retained): void
    {
        $this->changed(null, 'gateway.prune-web-builds', ['web_root' => $webRoot, 'retained' => $retained], 'web', 'gateway.release_web_install_failed');
    }

    public function databaseBytes(string $database): int
    {
        $result = $this->run(null, 'gateway.inspect-release-database', ['database' => $database], 'snapshot', 'gateway.release_snapshot_failed');
        if (! is_int($result['bytes'] ?? null) || $result['bytes'] < 0) {
            throw $this->malformed('snapshot', 'gateway.release_snapshot_failed');
        }

        return $result['bytes'];
    }

    public function snapshot(string $database, string $home, string $id, int $kept, int $minimum, string $stepLock): string
    {
        $result = $this->run(null, 'gateway.snapshot-release-database', [
            'database' => $database, 'orbit_home' => $home, 'release' => $id,
            'kept_snapshots' => $kept, 'minimum_free_bytes' => $minimum, 'step_lock' => $stepLock,
        ], 'snapshot', 'gateway.release_snapshot_failed');
        $path = $result['path'] ?? null;
        if (! is_string($path) || dirname($path) !== $home.'/backups'
            || ! preg_match('/\Apre-'.preg_quote($id, '/').'-[0-9TZ-]+[a-f0-9]+\.sqlite\z/', basename($path))) {
            throw $this->malformed('snapshot', 'gateway.release_snapshot_failed');
        }

        return $path;
    }

    public function migrate(GatewayReleaseLayout $layout, string $id, string $stepLock): void
    {
        $this->changed($layout, 'gateway.migrate-release', ['release' => $id, 'step_lock' => $stepLock], 'migrate', 'gateway.release_migrate_failed');
    }

    public function checkMigrations(GatewayReleaseLayout $layout, string $id, string $stepLock): void
    {
        try {
            $result = $this->run($layout, 'gateway.check-release-migrations', ['release' => $id, 'step_lock' => $stepLock], 'finalize', 'gateway.release_schema_pending');
            if (($result['complete'] ?? null) !== true) {
                throw $this->malformed('finalize', 'gateway.release_schema_pending');
            }
        } catch (GatewayReleaseException $error) {
            throw new GatewayReleaseException('finalize', 'gateway.release_schema_pending', 'Migration status is unavailable or migrations remain pending. Keep the hold.', previous: $error);
        }
    }

    /** @return array{path: string, sha256: string} */
    public function protectRecovery(string $home, int $record, string $sha, string $source): array
    {
        $result = $this->run(null, 'gateway.protect-release-recovery', [
            'identity' => ['orbit_home' => $home, 'record' => $record, 'sha' => $sha], 'source' => $source,
        ], 'recovery', 'gateway.release_recovery_invalid');
        if (($result['path'] ?? null) !== $home.'/recovery/stage-'.$record.'-'.$sha.'.sqlite'
            || ! is_string($result['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $result['sha256']) !== 1) {
            throw $this->malformed('recovery', 'gateway.release_recovery_invalid');
        }

        return ['path' => $result['path'], 'sha256' => $result['sha256']];
    }

    public function verifyRecovery(string $home, int $record, string $sha, string $hash): void
    {
        $result = $this->run(null, 'gateway.verify-release-recovery', [
            'identity' => ['orbit_home' => $home, 'record' => $record, 'sha' => $sha], 'sha256' => $hash,
        ], 'recovery', 'gateway.release_recovery_invalid');
        if (($result['verified'] ?? null) !== true) {
            throw $this->malformed('recovery', 'gateway.release_recovery_invalid');
        }
    }

    /** @param array<string, mixed> $attributes */
    public function writeReceipt(string $home, int $record, string $sha, array $attributes): void
    {
        $this->changed(null, 'gateway.write-release-receipt', [
            'identity' => ['orbit_home' => $home, 'record' => $record, 'sha' => $sha], 'attributes' => $attributes,
        ], 'abandon', 'gateway.release_restore_required');
    }

    /** @return array<string, mixed> */
    public function readReceipt(string $home, int $record, string $sha): array
    {
        $result = $this->run(null, 'gateway.read-release-receipt', ['orbit_home' => $home, 'record' => $record, 'sha' => $sha], 'abandon', 'gateway.release_restore_required');
        $attributes = $result['attributes'] ?? null;
        if (! is_array($attributes)) {
            throw $this->malformed('abandon', 'gateway.release_restore_required');
        }
        $values = [];
        foreach ($attributes as $key => $value) {
            if (! is_string($key)) {
                throw $this->malformed('abandon', 'gateway.release_restore_required');
            }
            $values[$key] = $value;
        }

        return $values;
    }

    /** @return array<string, mixed> */
    public function inspectHold(GatewayReleaseLayout $layout, string $targetSha, int $callerPid): array
    {
        return $this->run($layout, 'gateway.inspect-release-hold', ['target_sha' => $targetSha, 'caller_pid' => $callerPid], 'drain', 'gateway.release_not_drained');
    }

    /** @return array<string, mixed> */
    public function handoffPhase(GatewayReleaseLayout $layout, string $id, string $phase, string $stepLock): array
    {
        $required = match ($phase) {
            'serve' => 'caddy',
            'schedule' => 'scheduler',
            default => throw $this->malformed('handoff', 'gateway.release_handoff_failed'),
        };
        $result = $this->run($layout, 'gateway.handoff-release', [
            'release' => GatewayReleaseCommit::assertId($id), 'phase' => $phase, 'step_lock' => $stepLock,
        ], 'handoff', 'gateway.release_handoff_failed');
        if (! is_bool($result['succeeded'] ?? null) || ! is_array($result['report'] ?? null)) {
            throw $this->malformed('handoff', 'gateway.release_handoff_failed');
        }
        $data = [];
        foreach ($result['report'] as $key => $value) {
            if (! is_string($key)) {
                throw $this->malformed('handoff', 'gateway.release_handoff_failed');
            }
            $data[$key] = $value;
        }
        if (! $result['succeeded'] || ! is_string($data[$required] ?? null)) {
            throw new GatewayReleaseException(
                'handoff',
                is_string($data['error_code'] ?? null) ? $data['error_code'] : 'gateway.release_handoff_failed',
                is_string($data['message'] ?? null) ? $data['message'] : "The runtime handoff [{$phase}] to release [{$id}] failed.",
                500,
            );
        }

        return $data;
    }

    /** @param array<string, int> $retention */
    public function convergeUnits(string $application, string $home, string $user, array $retention): void
    {
        try {
            $this->changed(null, 'gateway.converge-release-units', ['application' => $application, 'orbit_home' => $home, 'user' => $user, 'retention' => $retention], 'gateway-release-units', 'gateway.release_units_install_failed');
        } catch (GatewayReleaseException $error) {
            $refused = $error->getPrevious();
            $stage = $refused instanceof AgentCommandRefused ? ($refused->details['stage'] ?? null) : null;
            throw new NodeProvisioningException(is_string($stage) ? $stage : 'gateway-release-units', 'gateway.release_units_install_failed', 'The Gateway release units could not be converged.', previous: $error);
        }
    }

    public function startRun(int $record): void
    {
        try {
            $this->changed(null, 'gateway.start-release-run', ['record' => $record], 'start', 'gateway.release_unit_failed');
        } catch (GatewayReleaseException $error) {
            throw $this->unitFailed($record, $error);
        }
    }

    public function runState(int $record): string
    {
        try {
            $result = $this->run(null, 'gateway.inspect-release-run', ['record' => $record], 'start', 'gateway.release_unit_failed');
            $state = $result['state'] ?? null;
            if (! is_string($state) || ! in_array($state, ['active', 'activating', 'reloading', 'deactivating', 'inactive', 'failed', 'unknown', 'maintenance'], true)) {
                throw $this->malformed('start', 'gateway.release_unit_failed');
            }

            return $state;
        } catch (GatewayReleaseException $error) {
            throw $this->unitFailed($record, $error);
        }
    }

    private function unitFailed(int $record, GatewayReleaseException $previous): GatewayReleaseException
    {
        return new GatewayReleaseException('start', 'gateway.release_unit_failed', "Release record {$record} could not be started or observed. Run orbit:gateway-web on the Gateway to install the release units.", 503, previous: $previous);
    }

    /** @param array<string, mixed> $input */
    private function changed(?GatewayReleaseLayout $layout, string $command, array $input, string $step, string $failure): void
    {
        $result = $this->run($layout, $command, $input, $step, $failure);
        if (! is_bool($result['changed'] ?? null)) {
            throw $this->malformed($step, $failure);
        }
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function run(?GatewayReleaseLayout $layout, string $command, array $input, string $step, string $failure): array
    {
        $node = GatewayCommandNode::find(converging: $command === 'gateway.converge-release-units');
        if (! $node instanceof Node) {
            throw new GatewayReleaseException($step, 'gateway.release_gateway_node_missing', 'No active Gateway Node exists for release commands.', 500);
        }
        try {
            return $this->agents->run($node, $command, $layout === null ? $input : ['checkout' => $layout->applicationPath, ...$input]);
        } catch (AgentCommandRefused $refused) {
            $status = match ($refused->errorCode) {
                'gateway.release_web_build_invalid', 'gateway.release_web_build_missing' => 422,
                'gateway.release_web_directory_missing', 'gateway.release_web_install_failed', 'gateway.release_web_publish_failed' => 500,
                'gateway.release_not_prepared', 'gateway.release_commit_invalid', 'gateway.release_commit_unknown' => 422,
                'gateway.release_switch_failed', 'gateway.release_source_unknown',
                'gateway.release_fetch_failed', 'gateway.release_worktree_failed', 'gateway.release_link_failed',
                'gateway.release_dependencies_failed', 'gateway.release_access_failed', 'gateway.release_revision_failed',
                'gateway.release_configuration_failed', 'gateway.release_lock_unavailable' => 500,
                'gateway.release_snapshot_unavailable', 'gateway.release_snapshot_failed', 'gateway.release_migrate_failed', 'gateway.release_handoff_failed' => 500,
                default => 409,
            };
            if (in_array($command, ['gateway.inspect-release-preparation', 'gateway.prepare-release', 'gateway.finish-release'], true)) {
                $step = match ($refused->errorCode) {
                    'gateway.release_commit_invalid' => 'resolve',
                    'gateway.release_layout_invalid', 'gateway.release_layout_missing', 'gateway.release_path_conflict' => 'layout',
                    'gateway.release_fetch_failed', 'gateway.release_commit_unknown' => 'fetch',
                    'gateway.release_disk_low', 'gateway.release_conflict', 'gateway.release_current_incomplete', 'gateway.release_worktree_failed' => 'worktree',
                    'gateway.release_link_failed' => 'link',
                    'gateway.release_dependencies_failed' => 'dependencies',
                    'gateway.release_access_failed' => 'access',
                    'gateway.release_revision_failed' => 'revision',
                    'gateway.release_configuration_failed' => 'configuration',
                    'gateway.release_lock_unavailable', 'gateway.release_in_progress' => 'lock',
                    default => $step,
                };
            }
            $sha = $refused->details['sha'] ?? $input['sha'] ?? null;
            throw new GatewayReleaseException($step, $refused->errorCode, $refused->getMessage(), $status, sha: is_string($sha) && GatewayReleaseCommit::isSha($sha) ? $sha : null, previous: $refused, phase: $refused->errorCode === 'gateway.release_recovery_foreign_keys' ? ['violations' => $refused->details['violations'] ?? [], 'limit' => 20] : []);
        } catch (AgentRunnerFailure $error) {
            throw new GatewayReleaseException($step, $failure, 'The agent returned no release command result.', 500, previous: $error);
        }
    }

    private function malformed(string $step, string $code): GatewayReleaseException
    {
        return new GatewayReleaseException($step, $code, 'The agent returned an invalid release command result.', 500);
    }
}
