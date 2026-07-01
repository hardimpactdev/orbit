<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Commands\GatewayCommand;

final readonly class ToolListDataListRenderer
{
    public function __construct(
        private GatewayCommand $command,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $tools
     */
    public function render(array $tools): void
    {
        foreach ($this->toolsGroupedByNode($tools) as $node => $nodeTools) {
            $this->command->line("Node: {$node}");

            foreach ($nodeTools as $tool) {
                $this->renderTool($tool);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, list<array<string, mixed>>>
     */
    private function toolsGroupedByNode(array $tools): array
    {
        $grouped = [];

        foreach ($tools as $tool) {
            $node = $this->toolString($tool, 'node');
            $grouped[$node][] = $tool;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $tool
     */
    private function renderTool(array $tool): void
    {
        $this->command->line('  '.$this->toolString($tool, 'name'));
        $this->command->line('    Expected: '.$this->toolString($tool, 'expected_state'));
        $this->command->line('    Managed: '.$this->toolString($tool, 'managed'));
        $this->command->line('    Version: '.$this->toolString($tool, 'version'));
    }

    /**
     * @param  array<string, mixed>  $tool
     */
    private function toolString(array $tool, string $key): string
    {
        $value = $tool[$key] ?? null;

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }
}
