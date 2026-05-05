<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Apps\RemoveApp;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Apps\RemoveAppRequest;
use App\Http\Gateway\Responses\Apps\AppRemoveResponse;
use App\Models\App;
use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:remove
    {app? : App name or hostname}
    {--force : Confirm destructive operation without prompting}
    {--json : Output JSON}')]
#[Description('Remove an app and its owned artifacts')]
class AppRemoveCommand extends Command
{
    public function handle(RemoveApp $removeApp): int
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
            $selector = trim((string) $this->ask('App name or hostname'));
        }

        if ($selector === null) {
            return $this->failValidation('app', 'App name is required.');
        }

        if ($callerRole === 'control') {
            $consent = $this->confirmRemoval($selector);

            if (is_int($consent)) {
                return $consent;
            }

            return $this->forwardRemove($selector);
        }

        $app = $this->resolveApp($selector);

        if (! $app instanceof App) {
            return $this->failCommand(
                code: 'app.not_found',
                message: "App '{$selector}' not found.",
                meta: ['name' => $selector],
            );
        }

        $consent = $this->confirmRemoval($app->name);

        if (is_int($consent)) {
            return $consent;
        }

        if (! $this->wantsJson()) {
            $this->renderProgressTree();
        }

        $result = $removeApp->handle($app);

        return $this->successCommand($result);
    }

    private function confirmRemoval(string $name): ?int
    {
        if ($this->option('force')) {
            return null;
        }

        if (! $this->isInteractiveInput()) {
            return $this->failValidation('force', 'Use --force to remove this app.');
        }

        if ($this->confirm("Remove app '{$name}' and all owned artifacts? This cannot be undone.", false)) {
            return null;
        }

        return $this->failCommand(
            code: 'validation_failed',
            message: 'Operation cancelled.',
            meta: [],
        );
    }

    private function forwardRemove(string $selector): int
    {
        try {
            /** @var AppRemoveResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new RemoveAppRequest($selector))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to remove an app.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to remove an app.',
                meta: [],
            );
        }

        return $this->successCommand([
            'app' => is_array($dto->data['app'] ?? null) ? $dto->data['app'] : [],
            'result' => is_array($dto->data['result'] ?? null) ? $dto->data['result'] : ['action' => 'removed'],
            'cleanup' => is_array($dto->data['cleanup'] ?? null) ? $dto->data['cleanup'] : [],
            'warnings' => $dto->warnings,
        ]);
    }

    private function resolveApp(string $selector): ?App
    {
        return App::query()
            ->with(['node', 'processes'])
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

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    private function renderProgressTree(): void
    {
        $this->line('┌ Removing App');
        $this->line('○ Validate removal');
        $this->line('○ Apply and verify app removal');
        $this->line('○ Remove app-owned proxy routes');
        $this->line('○ Remove app-owned schedules');
        $this->line('○ Remove app-owned workspaces');
        $this->line('○ Stop and remove app processes');
        $this->line('○ Clean node-side runtime artifacts');
        $this->line('└ Working...');
    }

    /**
     * @param  array{
     *     app: array<string, mixed>,
     *     result: array{action: string},
     *     cleanup: array<string, mixed>,
     *     warnings: list<array<string, string>>
     * }  $result
     */
    private function successCommand(array $result): int
    {
        $data = [
            'app' => $result['app'],
            'result' => $result['result'],
            'cleanup' => $result['cleanup'],
        ];
        $warnings = $result['warnings'];

        if (! $this->wantsJson()) {
            $app = $result['app'];
            $name = (string) ($app['name'] ?? '');

            if ($warnings !== []) {
                $this->line("└ App `{$name}` removed with drift");
            }

            $this->line("App '{$name}' removed");

            if ($warnings !== []) {
                $this->line('  Drift detected:');

                foreach ($warnings as $warning) {
                    $family = (string) ($warning['family'] ?? 'app');
                    $message = (string) ($warning['message'] ?? $warning['code'] ?? 'cleanup drift');
                    $next = (string) ($warning['next_command'] ?? 'doctor --family='.$family.' --fix');

                    $this->line("  - {$family}: {$message} (run `{$next}`)");
                }
            }

            return self::SUCCESS;
        }

        $payload = [
            'success' => [
                'data' => $data,
            ],
        ];

        if ($warnings !== []) {
            $payload['success']['meta'] = [
                'warnings' => $warnings,
            ];
        }

        $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

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
                'meta' => $meta,
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
}
