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
    protected $signature = 'app:list
        {--json}';

    #[\Override]
    protected $description = 'List apps registered in the gateway registry.';

    public function handle(): int
    {
        if (! $this->wantsJson() && ! $this->input->isInteractive()) {
            return $this->renderFailure(
                'validation_failed',
                'Interactive app selection requires a terminal. Use --json for non-interactive output.',
                ['field' => 'app'],
            );
        }

        try {
            $response = $this->gatewayGet('/api/apps');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $apps = $this->appsFromGatewayResponse($response);

        if ($apps === []) {
            $this->line('No apps found.');

            return self::SUCCESS;
        }

        $rows = $this->dataTableRows($apps);

        try {
            $selected = datatable(
                headers: ['Name', 'Repository', 'Instances', 'Workspaces'],
                rows: $rows,
                label: 'Select an app',
                hint: 'Press / to search',
                required: true,
            );
        } catch (Throwable) {
            return $this->renderFailure('validation_failed', 'Operation cancelled.', ['field' => 'app']);
        }

        if (! is_string($selected) || ! array_key_exists($selected, $rows)) {
            return $this->renderFailure('validation_failed', 'Operation cancelled.', ['field' => 'app']);
        }

        return $this->call('app:show', ['app' => $selected]);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<array-key, mixed>>
     */
    private function appsFromGatewayResponse(array $response): array
    {
        $apps = $response['success']['data']['apps'] ?? null;

        if (! is_array($apps)) {
            return [];
        }

        return array_values(array_filter($apps, is_array(...)));
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
