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

        $this->renderWorkspace($workspace, $response);

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
     * @param  array<string, mixed>  $response
     */
    private function renderWorkspace(array $workspace, array $response): void
    {
        $node = is_array($response['success']['data']['node'] ?? null) ? $response['success']['data']['node'] : [];
        $inheritedProcesses = is_array($response['success']['data']['inherited_processes'] ?? null)
            ? $response['success']['data']['inherited_processes']
            : [];
        $agentIde = is_array($workspace['agent_ide'] ?? null) ? $workspace['agent_ide'] : [];

        $title = sprintf(
            'Workspace: %s.%s',
            (string) ($workspace['name'] ?? ''),
            (string) ($workspace['app'] ?? ''),
        );

        $this->renderShowDetails($title, [
            'URL' => $workspace['url'] ?? null,
            'Node' => $this->nodeLabel($node),
            'Path' => $workspace['path'] ?? null,
            'Agent IDE' => $this->agentIdeLabel($agentIde),
            'PHP' => $workspace['php_version'] ?? null,
            'Processes' => $this->processLabels($inheritedProcesses),
        ]);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nodeLabel(array $node): string
    {
        $name = is_string($node['name'] ?? null) ? $node['name'] : null;
        $host = is_string($node['host'] ?? null) ? $node['host'] : null;

        if ($name === null || $name === '') {
            return '—';
        }

        return $host === null || $host === '' ? $name : "{$name} ({$host})";
    }

    /**
     * @param  array<string, mixed>  $agentIde
     */
    private function agentIdeLabel(array $agentIde): string
    {
        $adapter = $agentIde['adapter'] ?? null;

        return is_string($adapter) && $adapter !== '' ? $adapter : '—';
    }

    /**
     * @param  list<array<string, mixed>>  $processes
     */
    private function processLabels(array $processes): string
    {
        if ($processes === []) {
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
}
