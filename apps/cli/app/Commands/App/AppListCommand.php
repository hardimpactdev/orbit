<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Support\Prompts\DataList;

final class AppListCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'app:list
        {--json}';

    #[\Override]
    protected $description = 'List apps registered in the gateway registry.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/apps');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $apps = $this->appsFromGatewayResponse($response);
        $inventory = $this->inventoryFromGatewayResponse($response);

        if ($apps === []) {
            $this->line('No apps found.');

            return self::SUCCESS;
        }

        new DataList([
            [
                'heading' => 'Apps',
                'items' => $this->dataListItems($apps, $inventory),
            ],
        ])->display();

        return self::SUCCESS;
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
     * @param  array<string, mixed>  $response
     * @return list<array<array-key, mixed>>
     */
    private function inventoryFromGatewayResponse(array $response): array
    {
        $inventory = $response['success']['data']['inventory'] ?? null;

        if (! is_array($inventory)) {
            return [];
        }

        return array_values(array_filter($inventory, is_array(...)));
    }

    /**
     * @param  list<array<array-key, mixed>>  $apps
     * @param  list<array<array-key, mixed>>  $inventory
     * @return list<array{
     *     label: string,
     *     properties: array<string, string>,
     * }>
     */
    private function dataListItems(array $apps, array $inventory): array
    {
        $items = [];
        $inventoryByApp = [];

        foreach ($inventory as $entry) {
            $appName = $this->appString($entry, 'app');

            if ($appName !== '—') {
                $inventoryByApp[$appName] = $entry;
            }
        }

        foreach ($apps as $app) {
            $appName = $this->appString($app, 'name');
            $placement = $inventoryByApp[$appName] ?? [];

            $items[] = [
                'label' => $appName,
                'properties' => [
                    'Repository' => $this->appString($app, 'repository'),
                    'Instances' => $this->countString($placement, 'instance_count'),
                    'Workspaces' => $this->countString($placement, 'workspace_count'),
                ],
            ];
        }

        return $items;
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
