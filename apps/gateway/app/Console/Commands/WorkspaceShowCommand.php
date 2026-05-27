<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\PromptsForRegistryEntities;
use App\Console\Commands\Concerns\RendersShowDetails;
use App\Exceptions\PromptAborted;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\ShowWorkspaceRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceShowResponse;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspaceShowPayload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Signature('workspace:show
    {name? : Workspace name}
    {--app= : Parent app slug}
    {--json : Output JSON}')]
#[Description('Show workspace registry details')]
class WorkspaceShowCommand extends Command
{
    use PromptsForRegistryEntities;
    use RendersShowDetails;

    public function handle(WorkspaceShowPayload $payload): int
    {
        $name = $this->stringArgument('name');
        $app = $this->stringOption('app');
        $onGateway = (bool) config('orbit.is_gateway', false);
        $path = null;

        if ($name === null) {
            $name = $this->resolveNameFromCwd($onGateway);
        }

        if ($name === null && ! $onGateway) {
            $path = realpath((string) getcwd()) ?: (string) getcwd();
        }

        if ($name === null && $this->isInteractiveInput()) {
            $selection = $this->promptForWorkspaceSelection($app);

            if ($selection instanceof GatewayApiException) {
                return $this->failCommand(
                    code: $selection->errorCode() ?? 'gateway_unavailable',
                    message: $selection->getMessage(),
                    meta: $selection->errorMeta(),
                );
            }

            $name = $selection['name'];
            $app ??= $selection['app'];
            $path = null;
        }

        if ($name === null && $path === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Workspace name is required.',
                meta: ['field' => 'name'],
            );
        }

        try {
            $workspace = $this->fetchWorkspace($name, $app, $path, $payload, $onGateway);
        } catch (GatewayApiException $e) {
            if ($this->shouldPromptForAmbiguousApp($e, $app)) {
                $app = $this->promptForApp($e->errorMeta(), (string) $name);
                try {
                    $workspace = $this->fetchWorkspace($name, $app, $path, $payload, $onGateway);
                } catch (GatewayApiException $retryException) {
                    return $this->failCommand(
                        code: $retryException->errorCode() ?? 'gateway_unavailable',
                        message: $retryException->getMessage() !== '' ? $retryException->getMessage() : 'Gateway connection is required to show workspace details.',
                        meta: $retryException->errorMeta(),
                    );
                }
            } else {
                return $this->failCommand(
                    code: $e->errorCode() ?? 'gateway_unavailable',
                    message: $e->getMessage() !== '' ? $e->getMessage() : 'Gateway connection is required to show workspace details.',
                    meta: $e->errorMeta(),
                );
            }
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to show workspace details.',
                meta: [],
            );
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['workspace' => $workspace], ['registry_only' => true]);
        }

        $this->renderHuman($workspace);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchWorkspace(?string $name, ?string $app, ?string $path, WorkspaceShowPayload $payload, bool $onGateway): array
    {
        if (! $onGateway) {
            /** @var WorkspaceShowResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new ShowWorkspaceRequest(name: $name, app: $app, path: $path))
                ->dto();

            return $dto->workspace;
        }

        if ($name === null) {
            throw new GatewayApiException('Workspace name is required.', 'validation_failed', [
                'field' => 'name',
            ]);
        }

        $matches = $this->matchingLocalWorkspaces($name, $app);

        if ($matches->isEmpty()) {
            throw new GatewayApiException("Workspace '{$name}' not found or not visible.", 'workspace.not_found', [
                'name' => $name,
            ]);
        }

        if ($app === null && $matches->count() > 1) {
            if ($this->isInteractiveInput()) {
                $selection = $this->promptForLocalWorkspaceMatches($matches);
                $app = $selection['app'];

                return $this->fetchWorkspace($name, $app, $path, $payload, $onGateway);
            }

            throw new GatewayApiException("Workspace name '{$name}' is ambiguous.", 'workspace.ambiguous_name', [
                'name' => $name,
                'apps' => $matches->map(fn (Workspace $workspace): ?string => $workspace->app?->name)->filter()->values()->all(),
            ]);
        }

        $data = $payload->forWorkspace($matches->firstOrFail());

        /** @var array<string, mixed> $workspace */
        $workspace = $data['workspace'];

        return $workspace;
    }

    /**
     * @return Collection<int, Workspace>
     */
    private function matchingLocalWorkspaces(string $name, ?string $app): Collection
    {
        return Workspace::query()
            ->with(['app.node', 'app.processes', 'proxyRoutes', 'runs'])
            ->where('name', $name)
            ->when($app !== null, fn (Builder $query): Builder => $query->whereHas('app', fn (Builder $query): Builder => $query->where('name', $app)))
            ->get();
    }

    private function resolveNameFromCwd(bool $onGateway): ?string
    {
        if (! $onGateway) {
            return null;
        }

        $cwd = realpath((string) getcwd()) ?: (string) getcwd();

        return Workspace::query()
            ->get()
            ->first(function (Workspace $workspace) use ($cwd): bool {
                $workspacePath = realpath($workspace->path) ?: $workspace->path;

                return $workspacePath === $cwd || str_starts_with($cwd, "{$workspacePath}/");
            })?->name;
    }

    /**
     * @return array{name: string, app: string|null}|GatewayApiException
     */
    private function promptForWorkspaceSelection(?string $app): array|GatewayApiException
    {
        try {
            return $this->promptForVisibleWorkspace(label: 'Select a workspace', app: $app);
        } catch (PromptAborted) {
            return new GatewayApiException('Operation cancelled.', 'validation_failed', []);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function promptForApp(array $meta, string $name): string
    {
        $apps = $meta['apps'] ?? [];

        if (! is_array($apps) || $apps === []) {
            throw new GatewayApiException('Workspace name is ambiguous.', 'workspace.ambiguous_name', $meta);
        }

        /** @var list<string> $choices */
        $choices = array_values(array_filter($apps, is_string(...)));

        $workspaces = array_map(fn (string $app): array => [
            'name' => $name,
            'app' => $app,
            'node' => '-',
            'url' => '-',
            'lifecycle_status' => '-',
        ], $choices);

        try {
            return (string) $this->promptForWorkspacePayloads('Select a workspace', $workspaces)['app'];
        } catch (PromptAborted) {
            throw new GatewayApiException('Operation cancelled.', 'validation_failed', []);
        }
    }

    /**
     * @param  Collection<int, Workspace>  $matches
     * @return array{name: string, app: string|null}
     */
    private function promptForLocalWorkspaceMatches(Collection $matches): array
    {
        $workspaces = $matches
            ->map(fn (Workspace $workspace): array => [
                'name' => $workspace->name,
                'app' => $workspace->app?->name,
                'node' => $workspace->app?->node?->name,
                'url' => $workspace->url(),
                'lifecycle_status' => $workspace->lifecycle_status->value,
            ])
            ->values()
            ->all();

        try {
            return $this->promptForWorkspacePayloads('Select a workspace', $workspaces);
        } catch (PromptAborted) {
            throw new GatewayApiException('Operation cancelled.', 'validation_failed', []);
        }
    }

    private function shouldPromptForAmbiguousApp(GatewayApiException $exception, ?string $app): bool
    {
        return $app === null
            && $exception->errorCode() === 'workspace.ambiguous_name'
            && $this->isInteractiveInput();
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    /**
     * @param  array<string, mixed>  $workspace
     */
    private function renderHuman(array $workspace): void
    {
        $runtime = $workspace['runtime_expectations'];
        $agentIde = $workspace['agent_ide'];
        $node = $workspace['node'];
        $processes = $workspace['inherited_processes'];
        $route = $workspace['route'];
        $latestSetupRun = $workspace['latest_setup_run'];

        $this->renderShowDetails("Workspace: {$workspace['name']}", [
            'App' => $workspace['app'] ?? null,
            'Branch' => $workspace['branch'] ?? null,
            'Node' => $this->nodeLabel($node),
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
            'Processes' => $this->processLabels($processes),
            'Route' => $this->routeLabel($route),
            'Latest setup' => $this->latestSetupLabel($latestSetupRun),
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

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function jsonSuccess(array $data, array $meta): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }
}
