<?php

declare(strict_types=1);

namespace App\Services\S3;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Nodes\RoleSettings\S3RoleSettings;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use InvalidArgumentException;
use Throwable;

/**
 * Provides node-family and tool-family doctor drift checks for the `s3` role.
 *
 * Execution lane: RemoteHostExecutor (SSH host substrate + Docker inspection).
 * See apps/docs/content/execution-lanes.md — all remote work here targets the
 * host substrate via `docker inspect` / container env probing. No orbit-runtime
 * exec, no host sqlite3/python3/php artisan forwarding.
 *
 * Node family owns:
 *  - WireGuard address presence (node.s3.wireguard_missing)
 *  - data_path setting validity (node.s3_data_path_invalid)
 *
 * Tool family owns:
 *  - RustFS tool row existence (tool.rustfs.row_missing)
 *  - RustFS credential completeness (tool.rustfs.credentials_missing)
 *  - RustFS runtime container presence/divergence (tool.rustfs.runtime_container_missing)
 *  - RustFS bind address posture (tool.rustfs.bind_public_interface)
 */
final readonly class S3DoctorProbe
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    /**
     * Node-family drift checks for an active s3 role assignment.
     *
     * Lane: RemoteHostExecutor — probes container env via `docker inspect`.
     * See apps/docs/content/execution-lanes.md.
     *
     * @return list<DriftEntry>
     */
    public function nodeDrift(Node $node, NodeRoleAssignment $assignment): array
    {
        $drift = [];

        $drift = array_merge($drift, $this->checkWireGuardAddress($node, $assignment));
        $drift = array_merge($drift, $this->checkDataPath($node, $assignment));

        return $drift;
    }

    /**
     * Tool-family drift checks for an active s3 role assignment.
     *
     * Lane: RemoteHostExecutor — probes container lifecycle and env via
     * `docker inspect`. See apps/docs/content/execution-lanes.md.
     *
     * @return list<DriftEntry>
     */
    public function toolDrift(Node $node, NodeRoleAssignment $assignment): array
    {
        $drift = [];

        $rustfsTool = $this->rustfsToolFor($node);

        if (! $rustfsTool instanceof NodeTool) {
            $drift[] = new DriftEntry(
                family: 'tool',
                key: 'tool.rustfs.row_missing',
                kind: DriftKind::Missing,
                summary: "No rustfs tool row exists on s3 node {$node->name}.",
                detail: [
                    'role' => $assignment->role,
                    'node' => $node->name,
                ],
            );

            return $drift;
        }

        $drift = array_merge($drift, $this->checkCredentials($node, $assignment, $rustfsTool));

        $probe = $this->containerProbe($node);

        if (! $probe->successful()) {
            $drift[] = new DriftEntry(
                family: 'tool',
                key: 'tool.rustfs.runtime_container_missing',
                kind: DriftKind::Unverifiable,
                summary: "RustFS runtime container could not be verified on node {$node->name}.",
                detail: [
                    'role' => $assignment->role,
                    'container' => S3RuntimeContainer::ContainerName,
                    'reason' => 'probe_failed',
                    'exit_code' => $probe->exitCode,
                    'stderr' => trim($probe->stderr),
                ],
            );

            return $drift;
        }

        $state = $this->parseKeyValueOutput($probe->stdout);

        if (($state['exists'] ?? null) !== '1' || ($state['running'] ?? null) !== 'true') {
            $exists = ($state['exists'] ?? null) === '1';
            $drift[] = new DriftEntry(
                family: 'tool',
                key: 'tool.rustfs.runtime_container_missing',
                kind: $exists ? DriftKind::Divergent : DriftKind::Missing,
                summary: 'RustFS runtime container is '.($exists ? 'stopped' : 'absent')." on node {$node->name}.",
                detail: [
                    'role' => $assignment->role,
                    'container' => S3RuntimeContainer::ContainerName,
                    'reason' => $exists ? 'container_stopped' : 'container_absent',
                    'exists' => $state['exists'] ?? null,
                    'running' => $state['running'] ?? null,
                ],
            );
        }

        $drift = array_merge($drift, $this->checkBindPosture($node, $assignment, $state));

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkWireGuardAddress(Node $node, NodeRoleAssignment $assignment): array
    {
        $address = trim((string) $node->wireguard_address);

        if ($address !== '') {
            return [];
        }

        return [
            new DriftEntry(
                family: 'node',
                key: 'node.s3.wireguard_missing',
                kind: DriftKind::Missing,
                summary: "Active s3 role node {$node->name} has a missing or empty WireGuard address.",
                detail: [
                    'role' => $assignment->role,
                    'node' => $node->name,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkDataPath(Node $node, NodeRoleAssignment $assignment): array
    {
        $settings = is_array($assignment->settings) ? $assignment->settings : [];

        try {
            S3RoleSettings::fromArray($settings);

            return [];
        } catch (InvalidArgumentException) {
            return [
                new DriftEntry(
                    family: 'node',
                    key: 'node.s3_data_path_invalid',
                    kind: DriftKind::Missing,
                    summary: "Active s3 role assignment on node {$node->name} has a missing, relative, or invalid data_path setting.",
                    detail: [
                        'role' => $assignment->role,
                        'node' => $node->name,
                        'data_path' => $settings['data_path'] ?? null,
                    ],
                ),
            ];
        }
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkCredentials(Node $node, NodeRoleAssignment $assignment, NodeTool $rustfsTool): array
    {
        if ($this->hasCompleteCredentials($rustfsTool)) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'tool',
                key: 'tool.rustfs.credentials_missing',
                kind: DriftKind::Missing,
                summary: "RustFS tool row on node {$node->name} is missing service-level credentials.",
                detail: [
                    'role' => $assignment->role,
                    'node' => $node->name,
                    'tool' => 'rustfs',
                ],
            ),
        ];
    }

    /**
     * @param  array<string, string>  $state
     * @return list<DriftEntry>
     */
    private function checkBindPosture(Node $node, NodeRoleAssignment $assignment, array $state): array
    {
        $expectedBind = trim((string) $node->wireguard_address);
        $observedAddress = $this->observedBindAddress($state);

        if ($observedAddress === null) {
            return [];
        }

        // The RUSTFS_ADDRESS env var contains the full bind address, e.g. "10.6.0.20:9000".
        // Extract the host portion for comparison.
        $observedHost = $this->extractBindHost($observedAddress);

        if ($observedHost === $expectedBind) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'tool',
                key: 'tool.rustfs.bind_public_interface',
                kind: DriftKind::Divergent,
                summary: "RustFS on node {$node->name} is bound to '{$observedHost}' instead of its WireGuard address '{$expectedBind}'.",
                detail: [
                    'role' => $assignment->role,
                    'container' => S3RuntimeContainer::ContainerName,
                    'expected_bind' => $expectedBind,
                    'observed_address' => $observedAddress,
                    'observed_host' => $observedHost,
                ],
            ),
        ];
    }

    private function hasCompleteCredentials(NodeTool $rustfsTool): bool
    {
        $credentials = $rustfsTool->credentials;

        if (! is_array($credentials)) {
            return false;
        }

        $fields = $credentials['fields'] ?? null;

        if (! is_array($fields)) {
            return false;
        }

        $accessKeyId = $fields['access_key_id'] ?? null;
        $secretAccessKey = $fields['secret_access_key'] ?? null;

        return is_string($accessKeyId) && $accessKeyId !== ''
            && is_string($secretAccessKey) && $secretAccessKey !== '';
    }

    /**
     * Run a remote probe against the orbit-rustfs container via docker inspect.
     *
     * Lane: RemoteHostExecutor — SSH host substrate + Docker container inspection.
     * See apps/docs/content/execution-lanes.md.
     *
     * The RUSTFS_ADDRESS env variable is extracted to determine the bind
     * posture of the container. A bind to the WireGuard address is correct;
     * a bind to 0.0.0.0 or any non-WireGuard address is drift. We prefer
     * container env introspection over host-shell `ss`/`netstat` because
     * the renderer sets RUSTFS_ADDRESS explicitly and that is the authoritative
     * binding intent.
     */
    private function containerProbe(Node $node): RemoteShellResult
    {
        $container = S3RuntimeContainer::ContainerName;

        return $this->runProbe($node, sprintf(
            <<<'SH'
# orbit-s3-doctor:runtime-probe
set -eu
container=%s

if ! docker container inspect "$container" >/dev/null 2>&1; then
    printf 'exists=0\nrunning=false\nenv_address=\n'
    exit 0
fi

running="$(docker container inspect --format '{{.State.Running}}' "$container" 2>/dev/null || printf 'false')"
env_address="$(docker container inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$container" 2>/dev/null | awk -F= '$1 == "RUSTFS_ADDRESS" {print $2; exit}')"

printf 'exists=1\nrunning=%%s\nenv_address=%%s\n' "$running" "$env_address"
SH,
            escapeshellarg($container),
        ), 's3-runtime-doctor-probe');
    }

    private function runProbe(Node $node, string $script, string $operation): RemoteShellResult
    {
        try {
            return $this->remoteShell->run($node, $script, [
                'throw' => false,
                'metadata' => [
                    'ORBIT_OPERATION_ID' => $operation,
                ],
            ]);
        } catch (Throwable $exception) {
            return new RemoteShellResult(
                exitCode: 1,
                stdout: '',
                stderr: $exception->getMessage(),
                durationMs: 0,
            );
        }
    }

    /**
     * @param  array<string, string>  $state
     */
    private function observedBindAddress(array $state): ?string
    {
        $envAddress = trim($state['env_address'] ?? '');

        return $envAddress !== '' ? $envAddress : null;
    }

    private function extractBindHost(string $address): string
    {
        // RUSTFS_ADDRESS is "host:port" — extract host portion only.
        $lastColon = strrpos($address, ':');

        if ($lastColon !== false) {
            return substr($address, 0, $lastColon);
        }

        return $address;
    }

    /**
     * @return array<string, string>
     */
    private function parseKeyValueOutput(string $output): array
    {
        $values = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[$key] = $value;
        }

        return $values;
    }

    private function rustfsToolFor(Node $node): ?NodeTool
    {
        return NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'rustfs')
            ->first();
    }
}
