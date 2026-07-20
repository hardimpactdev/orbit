<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use Throwable;

use function Laravel\Prompts\datatable;

final class AppListCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'project:list
        {--json}';

    #[\Override]
    protected $description = 'List projects registered in the gateway registry.';

    public function handle(): int
    {
        if (! $this->wantsJson() && ! $this->input->isInteractive()) {
            return $this->renderFailure(
                'validation_failed',
                'Interactive project selection requires a terminal. Use --json for non-interactive output.',
                ['field' => 'project'],
            );
        }

        try {
            $response = $this->gatewayGet('/api/projects');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $projects = $this->projectsFromGatewayResponse($response);

        if ($projects === []) {
            $this->line('No projects found.');

            return self::SUCCESS;
        }

        $rows = $this->dataTableRows($projects);

        try {
            $selected = datatable(
                headers: ['Name', 'Repository', 'Instances', 'Workspaces'],
                rows: $rows,
                label: 'Select a project',
                hint: 'Press / to search',
                required: true,
            );
        } catch (Throwable) {
            return $this->renderFailure('validation_failed', 'Operation cancelled.', ['field' => 'project']);
        }

        if (! is_string($selected) || ! array_key_exists($selected, $rows)) {
            return $this->renderFailure('validation_failed', 'Operation cancelled.', ['field' => 'project']);
        }

        return $this->call('project:show', ['project' => $selected]);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<array-key, mixed>>
     */
    private function projectsFromGatewayResponse(array $response): array
    {
        $projects = $response['success']['data']['projects'] ?? null;

        if (! is_array($projects)) {
            return [];
        }

        return array_values(array_filter($projects, is_array(...)));
    }

    /**
     * @param  list<array<array-key, mixed>>  $apps
     * @return array<string, array<int, string>>
     */
    private function dataTableRows(array $apps): array
    {
        $rows = [];

        foreach ($apps as $app) {
            $appName = $this->appString($app, 'name');

            if ($appName === '—') {
                continue;
            }

            $rows[$appName] = [
                $appName,
                $this->appString($app, 'repository'),
                $this->countString($app, 'instance_count'),
                $this->countString($app, 'workspace_count'),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function appString(array $row, string $key): string
    {
        if (array_key_exists($key, $row) && is_scalar($row[$key]) && (string) $row[$key] !== '') {
            return (string) $row[$key];
        }

        return '—';
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function countString(array $row, string $key): string
    {
        return array_key_exists($key, $row) && is_int($row[$key]) && $row[$key] >= 0 ? (string) $row[$key] : '0';
    }
}
