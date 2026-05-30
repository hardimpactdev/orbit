<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Commands\Concerns\PromptsForGatewayRegistryEntities;
use App\Commands\Concerns\RendersShowDetails;
use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class WorkspaceShowCommand extends GatewayCommand
{
    use PromptsForGatewayRegistryEntities;
    use RendersShowDetails;
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'workspace:show
        {name? : Workspace name}
        {--app= : Parent app slug}
        {--json}';

    #[\Override]
    protected $description = 'Show one workspace from the gateway registry.';

    public function handle(): int
    {
        $name = $this->stringArgument('name');

        if ($name === null) {
            return $this->showFromPath();
        }

        $path = '/api/workspaces/'.rawurlencode($name);

        try {
            $response = $this->gatewayGet($path, $this->filledQuery([
                'app' => $this->stringOption('app'),
            ]));
        } catch (GatewayApiException $exception) {
            if ($this->canPromptForRegistrySelection() && $exception->gatewayErrorCode() === 'workspace.ambiguous_name') {
                return $this->showPromptedWorkspace($name);
            }

            return $this->renderGatewayFailure($exception);
        }

        return $this->renderWorkspaceResponse($response);
    }

    private function showFromPath(): int
    {
        $hostCwd = $this->hostCwd();

        if ($hostCwd === null) {
            if ($this->canPromptForRegistrySelection()) {
                return $this->showPromptedWorkspace();
            }

            return $this->renderFailure('validation_failed', 'Workspace name is required.', ['field' => 'name']);
        }

        try {
            $response = $this->gatewayGet('/api/workspaces/resolve-by-path', $this->filledQuery([
                'app' => $this->stringOption('app'),
                'path' => $hostCwd,
            ]));
        } catch (GatewayApiException $exception) {
            if ($this->canPromptForRegistrySelection() && $exception->gatewayErrorCode() === 'workspace.not_found') {
                return $this->showPromptedWorkspace();
            }

            return $this->renderGatewayFailure($exception);
        }

        return $this->renderWorkspaceResponse($response);
    }

    private function showPromptedWorkspace(?string $name = null): int
    {
        $workspace = $this->promptForVisibleWorkspace(
            app: $this->stringOption('app'),
            name: $name,
        );

        if (is_int($workspace)) {
            return $workspace;
        }

        try {
            $response = $this->gatewayGet('/api/workspaces/'.rawurlencode($workspace['name']), $this->filledQuery([
                'app' => $workspace['app'] ?? $this->stringOption('app'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderWorkspaceResponse($response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderWorkspaceResponse(array $response): int
    {
        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $workspace = $this->workspaceFromGatewayResponse($response);

        if ($workspace === null) {
            return $this->renderFailure('gateway_unavailable', 'Gateway response missing required workspace data.');
        }

        $this->renderWorkspace($workspace);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    private function workspaceFromGatewayResponse(array $response): ?array
    {
        $workspace = $response['success']['data']['workspace'] ?? null;

        return is_array($workspace) ? $workspace : null;
    }

    /**
     * @param  array<string, mixed>  $workspace
     */
    private function renderWorkspace(array $workspace): void
    {
        $runtime = is_array($workspace['runtime_expectations'] ?? null) ? $workspace['runtime_expectations'] : [];
        $agentIde = is_array($workspace['agent_ide'] ?? null) ? $workspace['agent_ide'] : [];

        $this->renderShowDetails('Workspace: '.(string) ($workspace['name'] ?? ''), [
            'App' => $workspace['app'] ?? null,
            'Branch' => $workspace['branch'] ?? null,
            'Node' => $this->nodeLabel($workspace['node'] ?? null),
            'URL' => $workspace['url'] ?? null,
            'Path' => $workspace['path'] ?? null,
            'Agent IDE' => $agentIde['adapter'] ?? null,
            'PHP' => sprintf(
                '%s (inherited from %s)',
                (string) ($runtime['php_version'] ?? '—'),
                (string) ($runtime['php_version_inherited_from'] ?? '—'),
            ),
            'Runtime container' => $runtime['runtime_container'] ?? null,
            'Hostname' => $runtime['hostname'] ?? null,
            'Processes' => $this->processLabels($workspace['inherited_processes'] ?? null),
            'Route' => $this->routeLabel($workspace['route'] ?? null),
            'Latest setup' => $this->latestSetupLabel($workspace['latest_setup_run'] ?? null),
        ]);
    }

    private function nodeLabel(mixed $node): string
    {
        if (! is_array($node)) {
            return '—';
        }

        $name = is_string($node['name'] ?? null) ? $node['name'] : null;
        $host = is_string($node['host'] ?? null) ? $node['host'] : null;

        if ($name === null || $name === '') {
            return '—';
        }

        return $host === null || $host === '' ? $name : "{$name} ({$host})";
    }

    private function processLabels(mixed $processes): string
    {
        if (! is_array($processes) || $processes === []) {
            return '—';
        }

        $labels = [];

        foreach ($processes as $process) {
            if (is_array($process) && is_string($process['name'] ?? null) && $process['name'] !== '') {
                $labels[] = $process['name'];
            }
        }

        return $labels === [] ? '—' : implode(', ', $labels);
    }

    private function routeLabel(mixed $route): string
    {
        if (! is_array($route) || ! is_string($route['host'] ?? null) || $route['host'] === '') {
            return '—';
        }

        $kind = is_string($route['kind'] ?? null) ? $route['kind'] : 'unknown';
        $owner = is_string($route['owner'] ?? null) ? $route['owner'] : 'unknown';

        return "{$route['host']} (kind: {$kind}, owner: {$owner})";
    }

    private function latestSetupLabel(mixed $latestSetupRun): string
    {
        if (! is_array($latestSetupRun)) {
            return '—';
        }

        $id = $latestSetupRun['run_id'] ?? null;
        $status = $latestSetupRun['status'] ?? null;
        $completed = $latestSetupRun['completed_at'] ?? null;

        return sprintf(
            '%s, %s, finished %s',
            is_scalar($id) ? (string) $id : 'unknown run',
            is_scalar($status) ? (string) $status : 'unknown status',
            is_scalar($completed) && $completed !== '' ? (string) $completed : 'not completed',
        );
    }
}
