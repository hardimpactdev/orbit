<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Exceptions\GatewayApiException;
use Throwable;

use function Laravel\Prompts\datatable;

trait PromptsForGatewayRegistryEntities
{
    private const string WORKSPACE_SELECTION_SEPARATOR = "\t";

    private const string SCHEDULE_SELECTION_SEPARATOR = "\t";

    protected function canPromptForRegistrySelection(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    protected function promptForVisibleApp(?string $node = null): string|int
    {
        try {
            $response = $this->gatewayGet('/api/apps', $this->filledQuery(['node' => $node]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $rows = $this->appPromptRows($this->registryPayloads($response, 'apps'));

        if ($rows === []) {
            return $this->renderFailure('app.not_found', 'No apps found.', ['field' => 'app']);
        }

        return $this->promptRegistryDataTable(
            label: 'Select an app',
            headers: ['App', 'Host', 'Node', 'Repository'],
            rows: $rows,
            field: 'app',
        );
    }

    protected function promptForVisibleNode(): string|int
    {
        try {
            $response = $this->gatewayGet('/api/nodes');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $rows = $this->nodePromptRows($this->registryPayloads($response, 'nodes'));

        if ($rows === []) {
            return $this->renderFailure('node.not_found', 'No nodes found.', ['field' => 'name']);
        }

        return $this->promptRegistryDataTable(
            label: 'Select a node',
            headers: ['Node', 'Roles', 'Host', 'Status'],
            rows: $rows,
            field: 'name',
        );
    }

    /**
     * @return array{name: string, app: string|null}|int
     */
    protected function promptForVisibleWorkspace(?string $app = null, ?string $node = null, ?string $name = null): array|int
    {
        try {
            $response = $this->gatewayGet('/api/workspaces', $this->filledQuery([
                'app' => $app,
                'node' => $node,
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $workspaces = $this->registryPayloads($response, 'workspaces');

        if ($name !== null) {
            $workspaces = array_values(array_filter(
                $workspaces,
                fn (array $workspace): bool => $this->registryString($workspace['name'] ?? null) === $name,
            ));
        }

        $rows = $this->workspacePromptRows($workspaces);

        if ($rows === []) {
            return $this->renderFailure('workspace.not_found', 'No workspaces found.', [
                'field' => 'name',
                ...($name !== null ? ['name' => $name] : []),
            ]);
        }

        $selected = $this->promptRegistryDataTable(
            label: 'Select a workspace',
            headers: ['Workspace', 'App', 'Node', 'URL', 'Status'],
            rows: $rows,
            field: 'name',
        );

        if (is_int($selected)) {
            return $selected;
        }

        return $this->decodeWorkspaceSelection($selected);
    }

    /**
     * @return array{name: string, app: string|null, node: string|null}|int
     */
    protected function promptForVisibleSchedule(?string $app = null, ?string $node = null): array|int
    {
        try {
            $response = $this->gatewayGet('/api/schedules', $this->filledQuery([
                'app' => $app,
                'node' => $node,
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        $rows = $this->schedulePromptRows($this->registryPayloads($response, 'schedules'));

        if ($rows === []) {
            return $this->renderFailure('schedule.not_found', 'No schedules found.', $this->filledQuery([
                'app' => $app,
                'node' => $node,
            ]));
        }

        $selected = $this->promptRegistryDataTable(
            label: 'Select a schedule',
            headers: ['Schedule', 'Scope', 'Target', 'Node', 'Interval', 'Execution', 'Status'],
            rows: $rows,
            field: 'name',
        );

        if (is_int($selected)) {
            return $selected;
        }

        return $this->decodeScheduleSelection($selected);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected function registrySuccessData(array $response): array
    {
        $success = is_array($response['success'] ?? null) ? $response['success'] : [];

        return is_array($success['data'] ?? null) ? $success['data'] : $response;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function registryPayloads(array $response, string $key): array
    {
        $payloads = $this->registrySuccessData($response)[$key] ?? [];

        if (! is_array($payloads)) {
            return [];
        }

        return array_values(array_filter($payloads, is_array(...)));
    }

    /**
     * @param  array<string, array<int, string>>  $rows
     */
    private function promptRegistryDataTable(string $label, array $headers, array $rows, string $field): string|int
    {
        try {
            $selected = datatable(
                headers: $headers,
                rows: $rows,
                label: $label,
                hint: 'Press / to search',
                required: true,
            );
        } catch (Throwable) {
            return $this->renderFailure('validation_failed', 'Operation cancelled.', ['field' => $field]);
        }

        if (is_string($selected) && array_key_exists($selected, $rows)) {
            return $selected;
        }

        if (is_int($selected) && array_key_exists($selected, $rows)) {
            return $selected;
        }

        return $this->renderFailure('validation_failed', 'Operation cancelled.', ['field' => $field]);
    }

    /**
     * @param  list<array<string, mixed>>  $apps
     * @return array<string, array<int, string>>
     */
    private function appPromptRows(array $apps): array
    {
        $rows = [];

        foreach ($apps as $app) {
            $name = $this->registryString($app['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $rows[$name] = [
                $name,
                $this->appHostFromRegistryPayload($app),
                $this->registryString($app['node'] ?? null) ?? '-',
                $this->registryString($app['repository'] ?? null) ?? '-',
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, array<int, string>>
     */
    private function nodePromptRows(array $nodes): array
    {
        $rows = [];

        foreach ($nodes as $node) {
            $name = $this->registryString($node['name'] ?? null);

            if ($name === null || ($this->registryString($node['status'] ?? null) ?? 'active') !== 'active') {
                continue;
            }

            $rows[$name] = [
                $name,
                $this->nodeRolesLabel($node),
                $this->nodePeerAddressLabel($node),
                $this->registryString($node['status'] ?? null) ?? '-',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nodePeerAddressLabel(array $node): string
    {
        $addresses = $node['addresses'] ?? null;
        $wireguardAddress = is_array($addresses) ? ($addresses['wireguard'] ?? null) : null;

        return $this->registryString($wireguardAddress ?? $node['wireguard_address'] ?? null) ?? '-';
    }

    /**
     * @param  list<array<string, mixed>>  $workspaces
     * @return array<string, array<int, string>>
     */
    private function workspacePromptRows(array $workspaces): array
    {
        $rows = [];

        foreach ($workspaces as $workspace) {
            $name = $this->registryString($workspace['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $app = $this->registryString($workspace['app'] ?? null);
            $rows[$this->workspaceSelectionKey($name, $app)] = [
                $name,
                $app ?? '-',
                $this->registryString($workspace['node'] ?? null) ?? '-',
                $this->registryString($workspace['url'] ?? null) ?? '-',
                $this->registryString($workspace['lifecycle_status'] ?? $workspace['status'] ?? null) ?? '-',
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $schedules
     * @return array<string, array<int, string>>
     */
    private function schedulePromptRows(array $schedules): array
    {
        $rows = [];

        foreach ($schedules as $schedule) {
            $name = $this->registryString($schedule['name'] ?? null);
            $scope = $this->registryString($schedule['scope'] ?? null);
            $target = $this->scheduleTargetName($schedule);

            if ($name === null || $scope === null || $target === null) {
                continue;
            }

            $rows[$this->scheduleSelectionKey($scope, $target, $name)] = [
                $name,
                $scope,
                $target,
                $this->scheduleTargetNode($schedule),
                $this->registryString($schedule['interval'] ?? null) ?? '-',
                $this->scheduleExecutionLabel($schedule),
                $this->registryString($schedule['status'] ?? null) ?? '-',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $app
     */
    private function appHostFromRegistryPayload(array $app): string
    {
        $domain = $this->registryString($app['domain'] ?? null);

        if ($domain !== null) {
            return $domain;
        }

        $url = $this->registryString($app['url'] ?? null);

        if ($url === null) {
            return $this->registryString($app['host'] ?? null) ?? '-';
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $url;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nodeRolesLabel(array $node): string
    {
        $roles = $node['roles'] ?? null;

        if (! is_array($roles) || $roles === []) {
            return '-';
        }

        $labels = [];

        foreach ($roles as $role) {
            if (! is_array($role)) {
                continue;
            }

            $name = $this->registryString($role['role'] ?? null);

            if ($name === null) {
                continue;
            }

            $status = $this->registryString($role['status'] ?? null) ?? 'active';
            $labels[] = $status === 'active' ? $name : "{$name} ({$status})";
        }

        return $labels === [] ? '-' : implode(', ', $labels);
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function scheduleTargetName(array $schedule): ?string
    {
        $target = $schedule['target'] ?? [];
        $target = is_array($target) ? $target : [];

        return $this->registryString($target['name'] ?? $schedule['target_name'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function scheduleTargetNode(array $schedule): string
    {
        $target = $schedule['target'] ?? [];
        $target = is_array($target) ? $target : [];

        return $this->registryString($target['node'] ?? $schedule['node'] ?? null) ?? '-';
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function scheduleExecutionLabel(array $schedule): string
    {
        $execution = $schedule['execution'] ?? null;

        if (is_array($execution)) {
            $type = $this->registryString($execution['type'] ?? null);
            $value = $this->registryString($execution['value'] ?? null);

            return $type !== null && $value !== null ? "{$type}: {$value}" : ($value ?? $type ?? '-');
        }

        return $this->registryString($schedule['execution_value'] ?? null) ?? '-';
    }

    private function workspaceSelectionKey(string $name, ?string $app): string
    {
        return $name.self::WORKSPACE_SELECTION_SEPARATOR.($app ?? '');
    }

    /**
     * @return array{name: string, app: string|null}
     */
    private function decodeWorkspaceSelection(string $selection): array
    {
        [$name, $app] = array_pad(explode(self::WORKSPACE_SELECTION_SEPARATOR, $selection, 2), 2, '');

        return [
            'name' => $name,
            'app' => $app !== '' ? $app : null,
        ];
    }

    private function scheduleSelectionKey(string $scope, string $target, string $name): string
    {
        return implode(self::SCHEDULE_SELECTION_SEPARATOR, [$scope, $target, $name]);
    }

    /**
     * @return array{name: string, app: string|null, node: string|null}
     */
    private function decodeScheduleSelection(string $selection): array
    {
        [$scope, $target, $name] = array_pad(explode(self::SCHEDULE_SELECTION_SEPARATOR, $selection, 3), 3, '');

        return [
            'name' => $name,
            'app' => $scope === 'app' && $target !== '' ? $target : null,
            'node' => $scope === 'node' && $target !== '' ? $target : null,
        ];
    }

    private function registryString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
