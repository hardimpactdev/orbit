<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Models\NodeTool;

final readonly class ToolsFixer
{
    public function __construct(
        private RemoteShell $remoteShell,
        private ?ToolCatalog $catalog = null,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fix(NodeTool $tool, DriftEntry $entry): ?array
    {
        $command = $this->repairCommand($tool, $entry);

        if ($command === null) {
            return null;
        }

        $tool->loadMissing('node');

        if ($tool->node === null) {
            return null;
        }

        $this->remoteShell->run($tool->node, $command, ['throw' => true]);

        return [
            'family' => 'tool',
            'node' => $tool->node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Repaired tool {$tool->name} from gateway intent.",
            'details' => [
                'tool' => $tool->name,
            ],
        ];
    }

    private function repairCommand(NodeTool $tool, DriftEntry $entry): ?string
    {
        $metadata = ($this->catalog ?? app(ToolCatalog::class))->probeMetadata($tool->name);
        $commands = is_array($metadata) && is_array($metadata['repair_commands'] ?? null)
            ? $metadata['repair_commands']
            : [];

        $key = match ($entry->key) {
            'tool.lifecycle_state_mismatch' => match ($tool->expected_state) {
                'running' => 'lifecycle_running',
                'stopped', 'installed' => 'lifecycle_stopped',
                default => null,
            },
            default => null,
        };

        if ($key === null) {
            return null;
        }

        $command = $commands[$key] ?? null;

        return is_string($command) && $command !== '' ? $command : null;
    }
}
