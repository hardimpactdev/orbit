<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Visible workspace rows from GET /api/workspaces for exact-slug resolution.
 */
final readonly class ApplicationLogWorkspaceInventory
{
    public function __construct(
        private ApplicationLogWorkspaceInventoryRows $rows = new ApplicationLogWorkspaceInventoryRows,
    ) {}

    /**
     * @param  array<string, mixed>  $data  success.data from GET /api/workspaces
     * @return list<array{name: string, app: string, instance: string, selector: string}>
     */
    public function entries(array $data): array
    {
        return $this->rows->all($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{ok: true, workspace: string, instance: string}|array{ok: false, reason: string, count: int}
     */
    public function resolveExactSlug(string $slug, array $data): array
    {
        $slug = trim($slug);
        $matches = [];

        foreach ($this->entries($data) as $entry) {
            if ($entry['name'] !== $slug) {
                continue;
            }

            $matches[] = $entry;
        }

        $count = count($matches);

        if ($count !== 1) {
            return [
                'ok' => false,
                'reason' => $count === 0 ? 'workspace_not_found' : 'workspace_slug_ambiguous',
                'count' => $count,
            ];
        }

        $match = $matches[0];
        $workspace = $match['name'];
        $instance = $match['selector'];

        if (! is_string($workspace) || $workspace === '' || ! is_string($instance) || $instance === '') {
            return [
                'ok' => false,
                'reason' => 'workspace_not_found',
                'count' => 0,
            ];
        }

        return [
            'ok' => true,
            'workspace' => $workspace,
            'instance' => $instance,
        ];
    }
}
