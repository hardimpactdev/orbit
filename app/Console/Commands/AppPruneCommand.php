<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Apps\PruneAppWorkspaces;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Apps\PruneAppRequest;
use App\Http\Gateway\Responses\Apps\PruneAppResponse;
use App\Models\App;
use App\Models\Node;
use App\Services\Apps\AppAgentIdeDefaults;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\text;

#[Signature('app:prune
    {app? : App name or hostname}
    {--dry-run : Preview stale workspaces without removing}
    {--force : Confirm destructive operation without prompting}
    {--json : Output JSON}')]
#[Description('Remove stale workspaces for an app')]
class AppPruneCommand extends Command
{
    public function handle(PruneAppWorkspaces $prune, AppAgentIdeDefaults $defaults): int
    {
        $callerRole = $this->callerRole();

        if ($callerRole === 'app' || $callerRole === 'unknown') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control or gateway node.',
                meta: ['caller_role' => $callerRole],
            );
        }

        $selector = $this->stringArgument('app');

        if ($selector === null && $this->isInteractiveInput()) {
            $selector = trim(text(label: 'App name or hostname', required: true));
        }

        if ($selector === null) {
            return $this->failValidation('app', 'App is required.');
        }

        $dryRun = $this->option('dry-run') === true;

        if (! $dryRun && ! $this->option('force') && ! $this->isInteractiveInput()) {
            return $this->failValidation('force', 'Use --force to prune stale workspaces.');
        }

        if ($callerRole === 'control') {
            return $this->forwardPrune($selector, $dryRun);
        }

        $app = $this->resolveApp($selector);

        if (! $app instanceof App) {
            return $this->failCommand(
                code: 'app.not_found',
                message: "App '{$selector}' not found.",
                meta: ['app' => $selector],
            );
        }

        $effectiveAdapter = $defaults->payloadFor($app)['effective_adapter'];

        if ($effectiveAdapter === null) {
            return $this->failCommand(
                code: 'app.no_agent_ide_adapter',
                message: 'No agent IDE adapter configured for this app.',
                meta: ['app' => $app->name],
            );
        }

        try {
            $result = $prune->handle($app, $dryRun);
        } catch (\RuntimeException $e) {
            return $this->failCommand(
                code: 'app.agent_ide_query_failed',
                message: $e->getMessage(),
                meta: ['app' => $app->name],
            );
        }

        return $this->successCommand($result);
    }

    private function forwardPrune(string $selector, bool $dryRun): int
    {
        try {
            /** @var PruneAppResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new PruneAppRequest($selector, $dryRun))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to prune app workspaces.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to prune app workspaces.',
                meta: [],
            );
        }

        return $this->successCommand([
            'app' => $dto->app,
            'stale_workspaces' => $dto->staleWorkspaces,
            'warnings' => $dto->warnings,
            'dry_run' => $dto->dryRun,
        ]);
    }

    private function resolveApp(string $selector): ?App
    {
        return App::query()
            ->with('node')
            ->get()
            ->filter(fn (App $app): bool => $app->name === $selector
                || $app->domain === $selector
                || $app->url() === "https://{$selector}")
            ->values()
            ->first();
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

    /**
     * @param  array<string, mixed>  $result
     */
    private function successCommand(array $result): int
    {
        $warnings = $result['warnings'] ?? [];

        if ($this->wantsJson()) {
            $data = [
                'app' => $result['app'],
                'stale_workspaces' => $result['stale_workspaces'],
                'dry_run' => $result['dry_run'],
            ];

            $meta = [];

            if ($warnings !== []) {
                $meta['warnings'] = $warnings;
            }

            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => $meta,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $dryRun = $result['dry_run'] ?? false;
        $staleWorkspaces = $result['stale_workspaces'] ?? [];

        if ($staleWorkspaces === []) {
            $this->line("No stale workspaces found for app '{$result['app']}'.");

            return self::SUCCESS;
        }

        $action = $dryRun ? 'Would remove' : 'Removed';
        $count = count($staleWorkspaces);

        $this->line("{$action} {$count} stale workspace".($count === 1 ? '' : 's')." for app '{$result['app']}':");

        foreach ($staleWorkspaces as $workspace) {
            $status = $workspace['removed'] ? '✓' : '✗';
            $this->line("  {$status} {$workspace['name']}");
        }

        if ($warnings !== []) {
            $this->line('  Warnings:');

            foreach ($warnings as $warning) {
                $message = $warning['message'] ?? $warning['code'] ?? 'unknown warning';
                $this->line("    - {$message}");
            }
        }

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

    private function failValidation(string $field, string $message): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: $message,
            meta: ['field' => $field],
        );
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }
}
