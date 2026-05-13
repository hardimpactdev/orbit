<?php

declare(strict_types=1);

namespace App\Console\Commands;

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

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('workspace:show
    {name? : Workspace name}
    {--app= : Parent app slug}
    {--json : Output JSON}')]
#[Description('Show workspace registry details')]
class WorkspaceShowCommand extends Command
{
    public function handle(WorkspaceShowPayload $payload): int
    {
        $name = $this->stringArgument('name');
        $app = $this->stringOption('app');
        $callerRole = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';
        $path = null;

        if ($name === null) {
            $name = $this->resolveNameFromCwd($callerRole);
        }

        if ($name === null && $callerRole !== 'gateway') {
            $path = realpath((string) getcwd()) ?: (string) getcwd();
        }

        if ($name === null && $this->isInteractiveInput()) {
            $name = $this->promptForName();
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
            $workspace = $this->fetchWorkspace($name, $app, $path, $payload, $callerRole);
        } catch (GatewayApiException $e) {
            if ($this->shouldPromptForAmbiguousApp($e, $app)) {
                $app = $this->promptForApp($e->errorMeta());
                try {
                    $workspace = $this->fetchWorkspace($name, $app, $path, $payload, $callerRole);
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
    private function fetchWorkspace(?string $name, ?string $app, ?string $path, WorkspaceShowPayload $payload, string $callerRole): array
    {
        if ($callerRole !== 'gateway') {
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
                $app = (string) select(
                    'Parent app',
                    $matches->map(fn (Workspace $workspace): ?string => $workspace->app?->name)->filter()->values()->all(),
                );

                return $this->fetchWorkspace($name, $app, $path, $payload, $callerRole);
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

    private function resolveNameFromCwd(string $callerRole): ?string
    {
        if ($callerRole !== 'gateway') {
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

    private function promptForName(): ?string
    {
        $name = text(label: 'Workspace name', required: true);

        $name = trim($name);

        return $name !== '' ? $name : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function promptForApp(array $meta): string
    {
        $apps = $meta['apps'] ?? [];

        if (! is_array($apps) || $apps === []) {
            throw new GatewayApiException('Workspace name is ambiguous.', 'workspace.ambiguous_name', $meta);
        }

        /** @var list<string> $choices */
        $choices = array_values(array_filter($apps, is_string(...)));

        return (string) select('Parent app', $choices);
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

        $this->line("Workspace: {$workspace['name']}");
        $this->line("App:       {$workspace['app']}");
        $this->line('Branch:    '.($workspace['branch'] ?? '(none)'));
        $this->line("Node:      {$node['name']} ({$node['host']})");
        $this->line("URL:       {$workspace['url']}");
        $this->line("Path:      {$workspace['path']}");
        $this->newLine();
        $this->line('Agent IDE:');
        $this->line('  Adapter:   '.($agentIde['adapter'] ?? '(none)'));
        $this->line("  Source:    {$agentIde['inherited_from']}");
        $this->line('  Discovery: '.($agentIde['workspace_discovery'] ?? '(none)'));
        $this->newLine();
        $this->line('Runtime Expectations:');
        $this->line("  PHP Version: {$runtime['php_version']} (inherited from {$runtime['php_version_inherited_from']})");
        $this->line("  FPM Pool:    {$runtime['fpm_pool']}");
        $this->line("  Hostname:    {$runtime['hostname']}");
        $this->newLine();
        $this->line('Inherited Processes:');

        if ($processes === []) {
            $this->line('  (none)');
        }

        foreach ($processes as $process) {
            $this->line("  - {$process['name']}");
        }

        if (is_array($route)) {
            $this->newLine();
            $this->line('Route:');
            $this->line("  - {$route['host']} (kind: {$route['kind']}, owner: {$route['owner']})");
        }

        $this->newLine();
        $this->line('Latest Setup Run:');

        if (! is_array($latestSetupRun)) {
            $this->line('  (never run)');
        } else {
            $this->line("  Run ID:    {$latestSetupRun['run_id']}");
            $this->line("  Status:    {$latestSetupRun['status']}");
            $this->line('  Finished:  '.($latestSetupRun['completed_at'] ?? '(not completed)'));
        }

        $this->newLine();
        $this->line("Note: This view reflects registry intent. Run 'doctor --family=workspace' to verify live reality.");
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
