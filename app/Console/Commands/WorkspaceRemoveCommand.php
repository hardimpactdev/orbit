<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Workspaces\RemoveWorkspace;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\RemoveWorkspaceRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceRemoveResponse;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Signature('workspace:remove
    {name? : Workspace name}
    {--app= : Parent app slug}
    {--keep-files : Preserve workspace files on the app node}
    {--force : Confirm destructive operation without prompting}
    {--json : Output JSON}')]
#[Description('Remove a workspace and its owned artifacts')]
class WorkspaceRemoveCommand extends Command
{
    use WithSpinner;
    use WithStepTree;

    public function handle(RemoveWorkspace $removeWorkspace): int
    {
        $callerRole = $this->callerRole();

        if ($callerRole === 'app' || $callerRole === 'unknown') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control or gateway node.',
                meta: ['caller_role' => $callerRole],
            );
        }

        $app = $this->stringOption('app');
        $workspaceFromCwd = null;
        $name = $this->stringArgument('name');

        if ($name === null) {
            $workspaceFromCwd = $this->resolveWorkspaceFromCwd($callerRole);
            $name = $workspaceFromCwd?->name;
            $app ??= $workspaceFromCwd?->app?->name;
        }

        if ($name === null) {
            return $this->failCommand(
                code: 'workspace.unresolved_cwd',
                message: 'Current directory is not inside a registered workspace.',
                meta: [],
            );
        }

        if (! $this->option('force')) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Use --force to remove this workspace.',
                meta: ['field' => 'force'],
            );
        }

        if ($callerRole === 'control') {
            return $this->forwardRemove($name, $app);
        }

        $matches = $this->matchingLocalWorkspaces($name, $app);

        if ($matches->isEmpty()) {
            return $this->failCommand(
                code: 'workspace.not_found',
                message: "Workspace '{$name}' not found in registry.",
                meta: array_filter([
                    'name' => $name,
                    'app' => $app,
                ], fn (?string $value): bool => $value !== null),
            );
        }

        if ($app === null && $matches->count() > 1) {
            return $this->failCommand(
                code: 'workspace.ambiguous_name',
                message: "Workspace name '{$name}' matches multiple apps.",
                meta: ['name' => $name],
            );
        }

        $keepFiles = $this->option('keep-files') === true;
        $workspace = $matches->firstOrFail();

        if (! $this->wantsJson()) {
            $result = null;
            $exitCode = $this->runStepTree(
                'Removing Workspace',
                $this->progressSteps($keepFiles, function () use ($removeWorkspace, $workspace, $keepFiles, &$result): array {
                    $result = $removeWorkspace->handle(
                        workspace: $workspace,
                        keepFiles: $keepFiles,
                    );

                    return $result;
                }),
                doneFooter: "Workspace '{$workspace->name}' removed.",
                failFooter: "Failed to remove workspace '{$workspace->name}'.",
            );

            if ($exitCode !== self::SUCCESS || ! is_array($result)) {
                return self::FAILURE;
            }

            return $this->successCommand($result);
        }

        $result = $removeWorkspace->handle(
            workspace: $workspace,
            keepFiles: $keepFiles,
        );

        return $this->successCommand($result);
    }

    /**
     * @return Collection<int, Workspace>
     */
    private function matchingLocalWorkspaces(string $name, ?string $app): Collection
    {
        return Workspace::query()
            ->with(['app.node', 'app.processes'])
            ->where('name', $name)
            ->when($app !== null, fn (Builder $query): Builder => $query->whereHas('app', fn (Builder $query): Builder => $query->where('name', $app)))
            ->get();
    }

    private function forwardRemove(string $name, ?string $app): int
    {
        try {
            /** @var WorkspaceRemoveResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new RemoveWorkspaceRequest(
                    name: $name,
                    app: $app,
                    keepFiles: $this->option('keep-files') === true,
                ))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to remove a workspace.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to remove a workspace.',
                meta: [],
            );
        }

        return $this->successCommand([
            ...$dto->data,
            'kept_files' => (bool) ($dto->meta['kept_files'] ?? false),
            'warnings' => is_array($dto->meta['warnings'] ?? null) ? array_values($dto->meta['warnings']) : [],
        ]);
    }

    private function resolveWorkspaceFromCwd(string $callerRole): ?Workspace
    {
        if ($callerRole !== 'gateway') {
            return null;
        }

        $cwd = realpath((string) getcwd()) ?: (string) getcwd();

        return Workspace::query()
            ->with('app')
            ->get()
            ->first(function (Workspace $workspace) use ($cwd): bool {
                $workspacePath = realpath($workspace->path) ?: $workspace->path;

                return $workspacePath === $cwd || str_starts_with($cwd, "{$workspacePath}/");
            });
    }

    private function callerRole(): string
    {
        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        if (! is_string($localRole) || $localRole === '') {
            return 'control';
        }

        if (! in_array($localRole, ['gateway', 'app', 'control'], true)) {
            return 'unknown';
        }

        return $localRole;
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    /**
     * @return list<array{key: string, label: string, doneLabel: string, run: callable}>
     */
    private function progressSteps(bool $keepFiles, callable $remove): array
    {
        return [
            [
                'key' => 'remove_workspace',
                'label' => 'Apply and verify workspace removal',
                'doneLabel' => 'Applied and verified workspace removal',
                'run' => function () use ($remove): string {
                    $remove();

                    return 'removed';
                },
            ],
            [
                'key' => 'stop_traffic',
                'label' => 'Stop traffic for workspace hostname',
                'doneLabel' => 'Stopped traffic for workspace hostname',
                'run' => fn (): string => 'done',
            ],
            [
                'key' => 'stop_processes',
                'label' => 'Stop inherited processes',
                'doneLabel' => 'Stopped inherited processes',
                'run' => fn (): string => 'done',
            ],
            [
                'key' => 'run_teardown',
                'label' => 'Run teardown steps',
                'doneLabel' => 'Ran teardown steps',
                'run' => fn (): string => 'done',
            ],
            [
                'key' => 'clean_php_fpm',
                'label' => 'Clean workspace PHP-FPM pool',
                'doneLabel' => 'Cleaned workspace PHP-FPM pool',
                'run' => fn (): string => 'done',
            ],
            [
                'key' => 'remove_worktree',
                'label' => 'Remove worktree',
                'doneLabel' => $keepFiles ? 'Skipped removing worktree' : 'Removed worktree',
                'run' => fn (): string => $keepFiles ? 'skip:--keep-files was set' : 'done',
            ],
        ];
    }

    /**
     * @param  array{
     *     name: string,
     *     app: string,
     *     action: string,
     *     proxy_routes_removed: int,
     *     processes_removed: int,
     *     fpm_config_removed: bool,
     *     worktree_removed: bool,
     *     teardown_steps_run: int,
     *     kept_files: bool,
     *     warnings: list<array<string, string>>
     * }  $result
     */
    private function successCommand(array $result): int
    {
        $warnings = $result['warnings'];
        $data = [
            'name' => $result['name'],
            'app' => $result['app'],
            'action' => $result['action'],
            'proxy_routes_removed' => $result['proxy_routes_removed'],
            'processes_removed' => $result['processes_removed'],
            'fpm_config_removed' => $result['fpm_config_removed'],
            'worktree_removed' => $result['worktree_removed'],
            'teardown_steps_run' => $result['teardown_steps_run'],
        ];
        $meta = [
            'kept_files' => $result['kept_files'],
        ];

        if (! $this->wantsJson()) {
            $this->line("Workspace '{$result['name']}' removed.");

            if ($warnings !== []) {
                $this->line('  Drift detected:');

                foreach ($warnings as $warning) {
                    $family = (string) ($warning['family'] ?? 'workspace');
                    $message = (string) ($warning['message'] ?? $warning['code'] ?? 'cleanup drift');
                    $next = (string) ($warning['next_command'] ?? 'doctor --fix --family='.$family.' --restore');

                    $this->line("  - {$family}: {$message} (run `{$next}`)");
                }
            }

            return self::SUCCESS;
        }

        if ($warnings !== []) {
            $meta['warnings'] = $warnings;
        }

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
        if (! $this->wantsJson()) {
            $this->error($message);

            return self::FAILURE;
        }

        $this->line(json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
