<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Data\Doctor\DriftEntry;
use App\Data\Nodes\RoleSettings\WebSocketRoleSettings;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\RemoteShell\RunsInternalCommands;
use InvalidArgumentException;
use Throwable;

final readonly class WebSocketDoctorProbe
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
        private WebSocketRuntimeContainerRenderer $runtimeRenderer,
        private WebSocketBackendName $backendName,
        private WebSocketValkeyResolver $valkeyResolver,
    ) {}

    /**
     * @return list<DriftEntry>
     */
    public function nodeDrift(Node $node, NodeRoleAssignment $assignment): array
    {
        return [
            ...$this->checkBackendCertificate($node, $assignment),
            ...$this->checkRuntimeBind($node, $assignment),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    public function toolDrift(Node $node, NodeRoleAssignment $assignment): array
    {
        $drift = [];
        $runtime = $this->runtimeProbe($node);

        if (! $runtime->successful()) {
            return [
                $this->runtimeUnavailableEntry(
                    node: $node,
                    assignment: $assignment,
                    detail: [
                        'reason' => 'runtime_probe_failed',
                        'exit_code' => $runtime->exitCode,
                        'stderr' => trim($runtime->stderr),
                    ],
                    kind: DriftKind::Unverifiable,
                ),
            ];
        }

        $runtimeState = $this->probeState($runtime);

        if (($runtimeState['exists'] ?? null) !== '1' || ($runtimeState['running'] ?? null) !== 'true') {
            $drift[] = $this->runtimeUnavailableEntry(
                node: $node,
                assignment: $assignment,
                detail: [
                    'reason' => ($runtimeState['exists'] ?? null) !== '1' ? 'runtime_missing' : 'runtime_stopped',
                    'container' => $this->runtimeRenderer->containerName($node),
                    'exists' => $runtimeState['exists'] ?? null,
                    'running' => $runtimeState['running'] ?? null,
                ],
                kind: ($runtimeState['exists'] ?? null) !== '1' ? DriftKind::Missing : DriftKind::Divergent,
            );
        }

        $settings = $this->settingsFrom($assignment);

        if (! $settings instanceof WebSocketRoleSettings) {
            return [
                ...$drift,
                $this->valkeyUnavailableEntry(
                    node: $node,
                    assignment: $assignment,
                    detail: [
                        'reason' => 'role_settings_invalid',
                    ],
                    kind: DriftKind::Divergent,
                ),
            ];
        }

        $valkeyNode = $this->valkeyResolver->usableValkeyNode($settings->valkeyNodeId);

        if (! $valkeyNode instanceof Node) {
            return [
                ...$drift,
                $this->valkeyUnavailableEntry(
                    node: $node,
                    assignment: $assignment,
                    detail: [
                        'reason' => 'valkey_node_unavailable',
                        'valkey_node_id' => $settings->valkeyNodeId,
                    ],
                    kind: DriftKind::Divergent,
                ),
            ];
        }

        if (($runtimeState['exists'] ?? null) !== '1' || ($runtimeState['running'] ?? null) !== 'true') {
            return $drift;
        }

        $valkey = $this->valkeyProbe($node);

        if ($valkey->successful()) {
            return $drift;
        }

        return [
            ...$drift,
            $this->valkeyUnavailableEntry(
                node: $node,
                assignment: $assignment,
                detail: [
                    'reason' => 'valkey_probe_failed',
                    'valkey_node' => $valkeyNode->name,
                    'valkey_node_id' => $valkeyNode->id,
                    'exit_code' => $valkey->exitCode,
                    'stderr' => trim($valkey->stderr),
                ],
                kind: DriftKind::Unverifiable,
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkBackendCertificate(Node $node, NodeRoleAssignment $assignment): array
    {
        $backendName = $this->backendName->forNode($node);
        $paths = $this->certificatePathsFor($backendName);
        $probe = $this->backendCertificateProbe($node, $backendName, $paths['cert'], $paths['key']);

        if (! $probe->successful()) {
            return [
                new DriftEntry(
                    family: 'node',
                    key: 'node.websocket.backend_cert_missing',
                    kind: DriftKind::Unverifiable,
                    summary: "WebSocket backend certificate material could not be verified on node {$node->name}.",
                    detail: [
                        'role' => $assignment->role,
                        'backend' => $backendName,
                        'cert_path' => $paths['cert'],
                        'key_path' => $paths['key'],
                        'exit_code' => $probe->exitCode,
                        'stderr' => trim($probe->stderr),
                    ],
                ),
            ];
        }

        $state = $this->probeState($probe);

        if (
            ($state['cert_exists'] ?? null) === '1'
            && ($state['key_exists'] ?? null) === '1'
            && ($state['cert_matches'] ?? null) === '1'
        ) {
            return [];
        }

        $kind = match (true) {
            ($state['cert_exists'] ?? null) !== '1', ($state['key_exists'] ?? null) !== '1' => DriftKind::Missing,
            ($state['cert_matches'] ?? null) === '' => DriftKind::Unverifiable,
            default => DriftKind::Divergent,
        };

        return [
            new DriftEntry(
                family: 'node',
                key: 'node.websocket.backend_cert_missing',
                kind: $kind,
                summary: "WebSocket backend certificate material for {$backendName} is missing or mismatched on node {$node->name}.",
                detail: [
                    'role' => $assignment->role,
                    'backend' => $backendName,
                    'cert_path' => $paths['cert'],
                    'key_path' => $paths['key'],
                    'cert_exists' => $state['cert_exists'] ?? null,
                    'key_exists' => $state['key_exists'] ?? null,
                    'cert_matches' => $state['cert_matches'] ?? null,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRuntimeBind(Node $node, NodeRoleAssignment $assignment): array
    {
        $probe = $this->runtimeProbe($node);

        if (! $probe->successful()) {
            return [
                new DriftEntry(
                    family: 'node',
                    key: 'node.websocket.bind_public_interface',
                    kind: DriftKind::Unverifiable,
                    summary: "WebSocket runtime bind posture could not be verified on node {$node->name}.",
                    detail: [
                        'role' => $assignment->role,
                        'container' => $this->runtimeRenderer->containerName($node),
                        'exit_code' => $probe->exitCode,
                        'stderr' => trim($probe->stderr),
                    ],
                ),
            ];
        }

        $state = $this->probeState($probe);
        $expectedBind = '0.0.0.0';
        $observedBind = $this->observedBindAddress($state);

        if ($observedBind === null || $observedBind === $expectedBind) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'node',
                key: 'node.websocket.bind_public_interface',
                kind: DriftKind::Divergent,
                summary: "WebSocket runtime on node {$node->name} is not bound to its expected container interface.",
                detail: [
                    'role' => $assignment->role,
                    'container' => $this->runtimeRenderer->containerName($node),
                    'expected_bind' => $expectedBind,
                    'observed_bind' => $observedBind,
                    'env_host' => $state['env_host'] ?? null,
                    'cmd_host' => $state['cmd_host'] ?? null,
                ],
            ),
        ];
    }

    /**
     * @param  array<string, string>  $state
     */
    private function observedBindAddress(array $state): ?string
    {
        $envHost = trim($state['env_host'] ?? '');

        if ($envHost !== '') {
            return $envHost;
        }

        $cmdHost = trim($state['cmd_host'] ?? '');

        return $cmdHost !== '' ? $cmdHost : null;
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function certificatePathsFor(string $backendName): array
    {
        return [
            'cert' => WebSocketCertificateInstaller::CertificateDirectory."/{$backendName}.crt",
            'key' => WebSocketCertificateInstaller::CertificateDirectory."/{$backendName}.key",
        ];
    }

    private function runtimeProbe(Node $node): RemoteShellResult
    {
        return $this->runRuntimeAction(
            node: $node,
            action: 'doctor:runtime-probe',
            payload: ['container' => $this->runtimeRenderer->containerName($node)],
            operation: 'websocket-runtime-doctor-probe',
        );
    }

    private function backendCertificateProbe(
        Node $node,
        string $backendName,
        string $certPath,
        string $keyPath,
    ): RemoteShellResult {
        return $this->runRuntimeAction(
            node: $node,
            action: 'doctor:backend-cert-probe',
            payload: [
                'backend' => $backendName,
                'cert' => $certPath,
                'key' => $keyPath,
            ],
            operation: 'websocket-backend-cert-doctor-probe',
        );
    }

    private function valkeyProbe(Node $node): RemoteShellResult
    {
        return $this->runRuntimeAction(
            node: $node,
            action: 'doctor:valkey-probe',
            payload: ['container' => $this->runtimeRenderer->containerName($node)],
            operation: 'websocket-valkey-doctor-probe',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function runRuntimeAction(Node $node, string $action, array $payload, string $operation): RemoteShellResult
    {
        try {
            $input = json_encode($payload, JSON_THROW_ON_ERROR);

            return $this->localExecutor->runInternal(
                node: $node,
                commandName: 'internal:websocket-runtime',
                arguments: [$action],
                transportOptions: [
                    'throw' => false,
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => $operation,
                    ],
                    'input' => $input,
                    'strict' => false,
                    'timeout' => 30,
                ],
            );
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
     * @return array<string, string>
     */
    private function probeState(RemoteShellResult $probe): array
    {
        $data = RemoteShellSuccessData::fromJsonEnvelope($probe);

        if (array_key_exists('stdout', $data) && is_string($data['stdout']) && $data['stdout'] !== '') {
            return $this->parseKeyValueOutput($data['stdout']);
        }

        $state = [];

        foreach (['exists', 'running', 'env_host', 'cmd_host', 'cert_exists', 'key_exists', 'cert_matches'] as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key])) {
                $state[$key] = $data[$key];
            }
        }

        if ($state !== []) {
            return $state;
        }

        return $this->parseKeyValueOutput($probe->stdout);
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

    private function settingsFrom(NodeRoleAssignment $assignment): ?WebSocketRoleSettings
    {
        try {
            return WebSocketRoleSettings::fromArray(is_array($assignment->settings) ? $assignment->settings : []);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function runtimeUnavailableEntry(
        Node $node,
        NodeRoleAssignment $assignment,
        array $detail,
        DriftKind $kind,
    ): DriftEntry {
        return new DriftEntry(
            family: 'tool',
            key: 'tool.websocket.reverb_unavailable',
            kind: $kind,
            summary: "WebSocket Reverb runtime is unavailable on node {$node->name}.",
            detail: [
                'role' => $assignment->role,
                ...$detail,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function valkeyUnavailableEntry(
        Node $node,
        NodeRoleAssignment $assignment,
        array $detail,
        DriftKind $kind,
    ): DriftEntry {
        return new DriftEntry(
            family: 'tool',
            key: 'tool.websocket.valkey_unavailable',
            kind: $kind,
            summary: "WebSocket Valkey is unavailable to the Reverb runtime on node {$node->name}.",
            detail: [
                'role' => $assignment->role,
                ...$detail,
            ],
        );
    }
}
