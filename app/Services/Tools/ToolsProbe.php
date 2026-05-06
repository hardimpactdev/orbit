<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeTool;

final readonly class ToolsProbe
{
    private const array ExpectedStates = ['installed', 'running', 'stopped', 'absent'];

    public function __construct(
        private ?RemoteShell $remoteShell = null,
        private ?ToolCatalog $catalog = null,
    ) {}

    public function key(): string
    {
        return 'tool';
    }

    public function label(): string
    {
        return 'Tools';
    }

    public function introspect(NodeTool $tool): ProbeSnapshot
    {
        $tool->loadMissing('node');

        if (! $tool->node instanceof Node || $tool->name === '') {
            return new ProbeSnapshot([]);
        }

        $metadata = ($this->catalog ?? app(ToolCatalog::class))->probeMetadata($tool->name);
        $binary = $metadata['binary'] ?? $tool->name;
        $versionCommand = $metadata['version_command'] ?? null;
        $service = $metadata['service'] ?? null;
        $script = 'path=$(command -v "$ORBIT_TOOL_BINARY" 2>/dev/null || true); if [ -z "$path" ]; then exit 1; fi; version=""; state="unknown";';

        if (is_string($versionCommand) && $versionCommand !== '') {
            $script .= ' version=$('.$versionCommand.' 2>/dev/null | head -n 1 || true);';
        }

        if (is_string($service) && $service !== '') {
            $script .= ' if systemctl is-active --quiet "$ORBIT_TOOL_SERVICE" 2>/dev/null; then state="running"; else state="stopped"; fi;';
        }

        $script .= ' printf "%s\t%s\t%s\n" "$path" "$version" "$state"';

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($tool->node, $script, [
            'throw' => false,
            'env' => [
                'ORBIT_TOOL_BINARY' => $binary,
                'ORBIT_TOOL_SERVICE' => is_string($service) ? $service : '',
            ],
        ]);
        $parts = explode("\t", trim($result->stdout), 3);

        return new ProbeSnapshot([
            $tool->name => [
                'installed' => $result->successful(),
                'path' => ($parts[0] ?? '') !== '' ? $parts[0] : null,
                'version' => ($parts[1] ?? '') !== '' ? $parts[1] : null,
                'state' => ($parts[2] ?? '') !== '' ? $parts[2] : null,
            ],
        ]);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        return [
            ...$this->checkRecordCompleteness($tool),
            ...$this->checkNodeEligibility($tool),
            ...$this->checkDefinition($tool),
            ...$this->checkCapabilityPresence($tool, $snapshot),
            ...$this->checkVersionState($tool, $snapshot),
            ...$this->checkLifecycleState($tool, $snapshot),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(NodeTool $tool): array
    {
        if (
            ! is_int($tool->node_id)
            || $tool->name === ''
            || ! in_array($tool->expected_state, self::ExpectedStates, true)
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "Tool record {$tool->name} is missing required fields.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkNodeEligibility(NodeTool $tool): array
    {
        $tool->loadMissing('node');

        if (! $tool->node instanceof Node) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Tool {$tool->name} points at a missing node.",
                ),
            ];
        }

        if ($tool->node->status !== 'active' || ! in_array($tool->node->role, ['gateway', 'app'], true)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'tool.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Tool {$tool->name} targets node {$tool->node->name}, which is not an active gateway or app node.",
                    detail: [
                        'node' => $tool->node->name,
                        'role' => $tool->node->role,
                        'status' => $tool->node->status,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkDefinition(NodeTool $tool): array
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        if ($tool->name !== '' && $catalog->supports($tool->name)) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.definition_missing',
                kind: DriftKind::Missing,
                summary: "Tool {$tool->name} is not present in the Orbit tool catalog.",
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkCapabilityPresence(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        if ($tool->expected_state === 'absent') {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) === true) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.capability_missing',
                kind: DriftKind::Missing,
                summary: "Tool {$tool->name} is missing on the target node.",
                detail: [
                    'tool' => $tool->name,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkVersionState(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        if ($tool->expected_version === null || $tool->expected_version === '') {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) !== true) {
            return [];
        }

        $version = is_string($observed['version'] ?? null) ? $observed['version'] : null;

        if ($version === null || str_starts_with($version, (string) $tool->expected_version)) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.version_mismatch',
                kind: DriftKind::Divergent,
                summary: "Tool {$tool->name} version differs from gateway intent.",
                detail: [
                    'tool' => $tool->name,
                    'expected_version' => $tool->expected_version,
                    'observed_version' => $version,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkLifecycleState(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        if (! in_array($tool->expected_state, ['running', 'stopped'], true)) {
            return [];
        }

        $observed = $snapshot->get($tool->name);

        if (($observed['installed'] ?? null) !== true) {
            return [];
        }

        $state = is_string($observed['state'] ?? null) ? $observed['state'] : null;

        if ($state === null || $state === 'unknown' || $state === $tool->expected_state) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'tool.lifecycle_state_mismatch',
                kind: DriftKind::Divergent,
                summary: "Tool {$tool->name} lifecycle state differs from gateway intent.",
                detail: [
                    'tool' => $tool->name,
                    'expected_state' => $tool->expected_state,
                    'observed_state' => $state,
                ],
            ),
        ];
    }
}
