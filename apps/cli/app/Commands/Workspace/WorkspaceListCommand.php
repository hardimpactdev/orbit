<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class WorkspaceListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'workspace:list
        {--instance= : Filter by instance (app.instance)}
        {--node= : Filter by owning node}
        {--json}';

    #[\Override]
    protected $description = 'List workspaces registered in the gateway registry.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/workspaces', $this->filledQuery([
                'instance' => $this->stringOption('instance'),
                'node' => $this->stringOption('node'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $workspaces = $this->workspacesFromGatewayResponse($response);

        if ($workspaces === []) {
            $this->line('No workspaces found.');

            return self::SUCCESS;
        }

        $this->renderWorkspaceTables($workspaces);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function workspacesFromGatewayResponse(array $response): array
    {
        $workspaces = $response['success']['data']['workspaces'] ?? null;

        if (! is_array($workspaces)) {
            return [];
        }

        return array_values(array_filter($workspaces, is_array(...)));
    }

    /**
     * Render one table per app, grouped under the owning node. The
     * gateway already sorts workspaces by node, then app, then workspace name,
     * so the incoming order is preserved.
     *
     * @param  list<array<string, mixed>>  $workspaces
     */
    private function renderWorkspaceTables(array $workspaces): void
    {
        /** @var array<string, array<string, list<array<string, mixed>>>> $groups */
        $groups = [];

        foreach ($workspaces as $workspace) {
            $node = $this->workspaceString($workspace, 'node');
            $app = $this->workspaceString($workspace, 'app');
            $groups[$node][$app][] = $workspace;
        }

        $first = true;

        foreach ($groups as $node => $apps) {
            foreach ($apps as $app => $appWorkspaces) {
                if (! $first) {
                    $this->newLine();
                }

                $first = false;

                $this->line("Node: {$node}");
                $this->line("App: {$app}");

                table(
                    headers: ['WORKSPACE', 'URL', 'LIFECYCLE STATUS'],
                    rows: array_map(fn (array $workspace): array => [
                        $this->workspaceString($workspace, 'name'),
                        $this->workspaceString($workspace, 'url'),
                        $this->workspaceString($workspace, 'lifecycle_status'),
                    ], $appWorkspaces),
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function workspaceString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }
}
