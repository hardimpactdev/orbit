<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Contracts\RemoteShell;
use App\Data\Nodes\InstalledCliArtifact;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleName;
use App\Exceptions\UpdateLeaseConflict;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use RuntimeException;
use Throwable;

final readonly class WorkloadNodeUpdater
{
    private const string DoctorCommand = 'orbit doctor --self --stream-json';

    private const int DoctorTimeoutSeconds = 120;

    public function __construct(
        private NodeRoleAssignments $roles,
        private NodeHostPaths $hostPaths,
        private RemoteShell $remoteShell,
        private UpdateLeaseManager $leases,
        private OperationRunRecorder $operationRuns,
        private FleetUpdateTargetSelector $targets,
        private FleetVersionProbe $fleetVersions,
        private GatewayCliArtifactRelay $artifactRelay,
        private WorkloadNodeDoctorIssueParser $doctorIssues,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function update(OperationRun $operationRun, OperationUpdatePlan $plan): array
    {
        $results = [];

        foreach ($this->targets->workloadNodes() as $node) {
            $results[] = $this->updateNode($operationRun, $plan, $node);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function updateNode(OperationRun $operationRun, OperationUpdatePlan $plan, Node $node): array
    {
        $this->operationRuns->appendStep(
            $operationRun->id,
            $this->eventKey($node),
            'running',
            "Updating workload node {$node->name}",
        );

        try {
            $result = $this->leases->withLease(
                resourceType: 'node',
                resourceKey: $node->name,
                operationRun: $operationRun,
                ownerToken: $this->ownerToken($operationRun, $node),
                ttlSeconds: $this->leaseTtlSeconds(),
                callback: fn (): array => $this->runRemoteUpdate($operationRun, $plan, $node),
            );
        } catch (UpdateLeaseConflict $exception) {
            $this->operationRuns->appendStep(
                $operationRun->id,
                $this->eventKey($node),
                'fail',
                $exception->getMessage(),
            );

            throw $exception;
        } catch (Throwable $exception) {
            $result = [
                ...$this->targetPayload($node),
                'status' => 'failed',
                'failed_step' => 'remote_update',
                'output' => $exception->getMessage(),
            ];
        }

        $status = $result['status'] ?? null;

        if ($status === 'skipped') {
            $this->operationRuns->appendStep(
                $operationRun->id,
                $this->eventKey($node),
                'done',
                "Workload node {$node->name} skipped: already up to date",
            );

            return $result;
        }

        if ($status === 'completed') {
            $this->operationRuns->appendStep(
                $operationRun->id,
                $this->eventKey($node),
                'done',
                $this->updatedMessage($node, $result['doctor_issues'] ?? null),
            );

            return $result;
        }

        $this->operationRuns->appendStep(
            $operationRun->id,
            $this->eventKey($node),
            'fail',
            is_string($result['output'] ?? null) ? $result['output'] : "Workload node {$node->name} update failed",
        );

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function runRemoteUpdate(OperationRun $operationRun, OperationUpdatePlan $plan, Node $node): array
    {
        if (! $this->fleetVersions->nodeNeedsCliUpdate($node, $plan)) {
            return [
                ...$this->targetPayload($node),
                'status' => 'skipped',
            ];
        }

        $this->operationRuns->appendStep(
            $operationRun->id,
            $this->eventKey($node),
            'running',
            "Installing CLI {$plan->target_version}",
        );

        $script = $this->remoteUpdateScript($operationRun, $plan, $node);
        $result = $this->remoteShell->run($node, $script, [
            'cwd' => $node->orbit_path,
            'timeout' => 300,
            'metadata' => $this->remoteShellMetadata($operationRun, $node),
        ]);

        if (! $result->successful()) {
            return [
                ...$this->targetPayload($node),
                'status' => 'failed',
                'failed_step' => 'remote_update',
                'output' => $this->output($result),
            ];
        }

        $this->operationRuns->appendStep(
            $operationRun->id,
            $this->eventKey($node),
            'running',
            'Recording installed CLI',
        );

        $this->recordInstalledCli($operationRun, $plan, $node);

        $this->operationRuns->appendStep(
            $operationRun->id,
            $this->eventKey($node),
            'running',
            'Running doctor',
        );

        return [
            ...$this->targetPayload($node),
            'status' => 'completed',
            'doctor_issues' => $this->runNodeDoctor($operationRun, $node),
        ];
    }

    /**
     * Run streaming `orbit doctor` in verify mode for the node as the final per-node step.
     * The verify is non-fatal: a non-zero issue count is surfaced per node but
     * does not by itself fail the node's update, and any failure to resolve the
     * count yields `null` (unknown).
     */
    private function runNodeDoctor(OperationRun $operationRun, Node $node): ?int
    {
        try {
            $result = $this->remoteShell->run($node, self::DoctorCommand, [
                'cwd' => $node->orbit_path,
                'timeout' => self::DoctorTimeoutSeconds,
                'metadata' => $this->remoteShellMetadata($operationRun, $node),
            ]);
        } catch (Throwable) {
            return null;
        }

        return $this->doctorIssues->fromOutput($result->output());
    }

    private function updatedMessage(Node $node, ?int $issues): string
    {
        if ($issues === null || $issues === 0) {
            return "Workload node {$node->name} updated";
        }

        $noun = $issues === 1 ? 'issue' : 'issues';

        return "Workload node {$node->name} updated ({$issues} {$noun})";
    }

    private function remoteUpdateScript(OperationRun $operationRun, OperationUpdatePlan $plan, Node $node): string
    {
        $artifact = $this->cliArtifact($operationRun, $plan, $node);
        $installRoot = rtrim($node->orbit_path, '/') ?: '/home/orbit/orbit';
        $roleImages = $this->requiredRoleImages($plan, $node);

        $lines = [
            'set -euo pipefail',
            'tmp="$(mktemp -d)"',
            'trap \'rm -rf "$tmp"\' EXIT',
            'ORBIT_CLI_SHA256='.$this->quote($artifact['sha256']),
            'check_sha256() {',
            '    file="$1"',
            '    if command -v sha256sum >/dev/null 2>&1; then',
            '        printf \'%s  %s\n\' "$ORBIT_CLI_SHA256" "$file" | sha256sum -c -',
            '        return',
            '    fi',
            '    if command -v shasum >/dev/null 2>&1; then',
            '        actual="$(shasum -a 256 "$file" | awk \'{ print $1 }\')"',
            '        test "$actual" = "$ORBIT_CLI_SHA256"',
            '        return',
            '    fi',
            '    echo "No SHA-256 checksum tool found." >&2',
            '    return 127',
            '}',
            'INSTALL_ROOT="${ORBIT_INSTALL_PATH:-'.$installRoot.'}"',
            'BIN_PATH="${ORBIT_BIN_PATH:-/usr/local/bin/orbit}"',
            'SHARED_BINARY_PATH="${ORBIT_SHARED_BINARY_PATH:-}"',
            'echo download_cli',
            'curl -fksSL '.$this->quote($artifact['url']).' -o "$tmp/orbit"',
            'check_sha256 "$tmp/orbit"',
            'echo install_cli',
            'install -d "$INSTALL_ROOT/bin"',
            'install -m 0755 "$tmp/orbit" "$INSTALL_ROOT/bin/orbit-binary"',
            'link_target="$INSTALL_ROOT/bin/orbit-binary"',
            'case "$BIN_PATH" in',
            '    /usr/local/bin/*)',
            '        if [ -z "$SHARED_BINARY_PATH" ]; then',
            '            link_name="$(basename "$BIN_PATH")"',
            '            SHARED_BINARY_PATH="/usr/local/lib/orbit/${link_name}-binary"',
            '        fi',
            '        link_target="$SHARED_BINARY_PATH"',
            '        shared_parent="$(dirname "$link_target")"',
            '        if [ -d "$shared_parent" ] && [ -w "$shared_parent" ]; then',
            '            install -d -m 0755 "$shared_parent"',
            '            install -m 0755 "$tmp/orbit" "$link_target"',
            '        elif [ "$(id -u)" -eq 0 ]; then',
            '            install -d -m 0755 "$shared_parent"',
            '            install -m 0755 "$tmp/orbit" "$link_target"',
            '        else',
            '            sudo -n install -d -m 0755 "$shared_parent"',
            '            sudo -n install -m 0755 "$tmp/orbit" "$link_target"',
            '        fi',
            '        ;;',
            'esac',
            'link_parent="$(dirname "$BIN_PATH")"',
            'if [ -w "$link_parent" ]; then',
            '    ln -sfn "$link_target" "$BIN_PATH"',
            'else',
            '    sudo -n ln -sfn "$link_target" "$BIN_PATH"',
            'fi',
            'echo reconcile_launcher',
            'resolved="$(command -v orbit 2>/dev/null || true)"',
            'case "$resolved" in',
            '    /*)',
            '        if [ "$resolved" != "$BIN_PATH" ] && [ "$(readlink -f "$resolved" 2>/dev/null || true)" != "$(readlink -f "$link_target" 2>/dev/null || true)" ]; then',
            '            if [ -w "$(dirname "$resolved")" ]; then',
            '                ln -sfn "$link_target" "$resolved" || true',
            '            else',
            '                sudo -n ln -sfn "$link_target" "$resolved" || true',
            '            fi',
            '        fi',
            '        ;;',
            'esac',
            'echo verify_cli',
            'check_sha256 "$INSTALL_ROOT/bin/orbit-binary"',
            'check_sha256 "$link_target"',
            'resolved_binary="$(readlink -f "$BIN_PATH" 2>/dev/null || printf %s "$BIN_PATH")"',
            'check_sha256 "$resolved_binary"',
            '"$BIN_PATH" --version --local',
        ];

        if ($roleImages !== []) {
            $lines[] = 'echo pull_required_images';

            foreach ($roleImages as $image) {
                $lines[] = 'docker pull '.$this->quote($image);
                $lines[] = 'docker image inspect '.$this->quote($image).' >/dev/null';
            }
        }

        $lines[] = 'echo verify';
        $lines[] = '"$BIN_PATH" --version --local';

        return implode("\n", $lines);
    }

    private function recordInstalledCli(OperationRun $operationRun, OperationUpdatePlan $plan, Node $node): void
    {
        $platform = CliArtifactPlatform::forNode($node);
        $artifact = $plan->cli_artifacts[$platform] ?? null;

        if (
            ! is_array($artifact)
            || ! is_string($artifact['url'] ?? null)
            || ! is_string($artifact['sha256'] ?? null)
        ) {
            throw new RuntimeException("Update plan does not contain a CLI artifact for platform [{$platform}].");
        }

        $installRoot = rtrim($node->orbit_path, '/') ?: '/home/orbit/orbit';

        $node->forceFill([
            'installed_cli' => InstalledCliArtifact::record(
                version: $plan->target_version,
                platform: $platform,
                sha256: $artifact['sha256'],
                source: $plan->manifest_source,
                buildId: $this->manifestBuildId($plan),
                artifactUrl: $artifact['url'],
                installedPath: "{$installRoot}/bin/orbit-binary",
                operationRunId: $operationRun->id,
            ),
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    private function remoteShellMetadata(OperationRun $operationRun, Node $node): array
    {
        $metadata = [
            'ORBIT_OPERATION_ID' => $operationRun->id,
        ];

        if (NodeHostPaths::isMacosPlatform($node->platform)) {
            $metadata['ORBIT_BIN_PATH'] = $this->hostPaths->homeDirectory($node).'/.local/bin/orbit';
        }

        return $metadata;
    }

    /**
     * @return array{url: string, sha256: string, source_url: string}
     */
    private function cliArtifact(OperationRun $operationRun, OperationUpdatePlan $plan, Node $node): array
    {
        $platform = CliArtifactPlatform::forNode($node);

        return $this->artifactRelay->artifactFor($operationRun, $plan, $platform);
    }

    /**
     * @return list<string>
     */
    private function requiredRoleImages(OperationUpdatePlan $plan, Node $node): array
    {
        $images = [];

        if ($this->roles->nodeHostsOrbitCaddy($node) && is_string($plan->role_images['orbit-caddy'] ?? null)) {
            $images[] = $plan->role_images['orbit-caddy'];
        }

        if (
            $this->roles->nodeHasActiveRole($node, NodeRoleName::WebSocket->value)
            && is_string($plan->role_images['orbit-websocket'] ?? null)
        ) {
            $images[] = $plan->role_images['orbit-websocket'];
        }

        return array_values(array_unique($images));
    }

    private function manifestBuildId(OperationUpdatePlan $plan): ?string
    {
        $buildId = $plan->manifest_snapshot['build_id'] ?? null;

        return is_string($buildId) && $buildId !== '' ? $buildId : null;
    }

    /**
     * @return array{target: string, node: string, role: string}
     */
    private function targetPayload(Node $node): array
    {
        return [
            'target' => $node->name,
            'node' => $node->name,
            'role' => $this->roleLabel($node),
        ];
    }

    private function roleLabel(Node $node): string
    {
        return $this->roles->assignmentRoleLabel($node);
    }

    private function output(RemoteShellResult $result): string
    {
        return trim($result->errorOutput() !== '' ? $result->errorOutput() : $result->output());
    }

    private function ownerToken(OperationRun $operationRun, Node $node): string
    {
        return hash('sha256', implode(':', [
            'update-runner',
            $operationRun->id,
            'node',
            $node->name,
        ]));
    }

    private function eventKey(Node $node): string
    {
        return "workload.{$node->name}";
    }

    private function leaseTtlSeconds(): int
    {
        $ttlSeconds = (int) config('orbit.updates.lease_ttl_seconds', 300);

        return max(1, $ttlSeconds);
    }

    private function quote(string $value): string
    {
        return escapeshellarg($value);
    }
}
