<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;
use App\Services\OrbitConfigStore;

use function Laravel\Prompts\table;

final class AppListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    /**
     * App registry rows are desired-state rows; the gateway emits no per-app
     * status field, so the human table shows the desired-state marker.
     */
    private const string AppDesiredStateStatus = 'expected';

    #[\Override]
    protected $signature = 'app:list
        {--node= : Filter by owning node}
        {--node-transport= : Node command transport preference (auto|agent-push|transitional-ssh-fallback)}
        {--json}';

    #[\Override]
    protected $description = 'List apps registered in the gateway registry.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/apps', $this->appListQuery());
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
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

        $this->renderAppTables($apps);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function appListQuery(): array
    {
        return new AppListQueryResolver(
            node: $this->stringOption('node'),
            defaultNode: app(OrbitConfigStore::class)->defaultNode(),
            gateway: $this->gateway(),
        )->resolve();
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
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
     * @param  list<array<string, mixed>>  $apps
     */
    private function renderAppTables(array $apps): void
    {
        $groups = [];

        foreach ($apps as $app) {
            $groups[$this->appString($app, 'node')][] = $app;
        }

        $first = true;

        foreach ($groups as $node => $nodeApps) {
            if (! $first) {
                $this->newLine();
            }

            $first = false;

            $this->line("Node: {$node}");

            table(
                headers: ['NAME', 'URL', 'STATUS'],
                rows: $this->rowsForNode($nodeApps),
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $nodeApps
     * @return list<array{string, string, string}>
     */
    private function rowsForNode(array $nodeApps): array
    {
        $rows = [];

        foreach ($nodeApps as $app) {
            $rows[] = [
                $this->appString($app, 'name'),
                $this->appString($app, 'url'),
                self::AppDesiredStateStatus,
            ];

            $workspaces = is_array($app['workspaces'] ?? null)
                ? array_values(array_filter($app['workspaces'], is_array(...)))
                : [];

            $lastIndex = count($workspaces) - 1;

            foreach ($workspaces as $index => $workspace) {
                $prefix = $index === $lastIndex ? '└─ ' : '├─ ';

                $rows[] = [
                    $prefix.$this->appString($workspace, 'name'),
                    $this->appString($workspace, 'url'),
                    $this->appString($workspace, 'lifecycle_status'),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function appString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }
}
