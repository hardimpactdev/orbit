<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\App;
use App\Models\Node;
use App\Services\Apps\AppAgentIdeDefaults;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:agent-ide
    {app? : App name or hostname}
    {agent_ide? : Agent IDE adapter (opencode, polyscope, inherit, or none)}
    {--force : Confirm destructive workspace cleanup without prompting}
    {--json : Output JSON}')]
#[Description('Set the default agent IDE for an app')]
class AppAgentIdeCommand extends Command
{
    public function handle(AppAgentIdeDefaults $defaults): int
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

        if ($selector === null) {
            return $this->failValidation('app', 'App is required.');
        }

        $agentIde = $this->stringArgument('agent_ide');

        if ($agentIde === null) {
            return $this->failValidation('agent_ide', 'Agent IDE adapter is required.');
        }

        if ($callerRole === 'control') {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to validate the adapter or list registered adapters.',
                meta: [],
            );
        }

        $app = $this->resolveApp($selector);

        if (! $app instanceof App) {
            return $this->failCommand(
                code: 'app.not_found',
                message: "App '{$selector}' not found.",
                meta: ['app' => $selector],
            );
        }

        if (! $defaults->isSupported($agentIde)) {
            return $this->failCommand(
                code: 'app.unsupported_adapter',
                message: "The adapter \"{$agentIde}\" is not supported.",
                meta: [
                    'adapter' => $agentIde,
                    'supported' => $defaults->supportedAdapters(),
                ],
            );
        }

        return $this->successCommand($defaults->set($app, $agentIde));
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
     * @param  array{
     *     app: array<string, mixed>,
     *     agent_ide: array{adapter: string|null, source: string, effective_adapter: string|null},
     *     cleanup: array{workspaces_removed: list<string>},
     *     action: string
     * }  $data
     */
    private function successCommand(array $data): int
    {
        if (! $this->wantsJson()) {
            $app = $data['app'];
            $adapter = $data['agent_ide']['effective_adapter'] ?? $data['agent_ide']['adapter'];

            if ($data['action'] === 'converged') {
                $this->line("App '".(string) ($app['name'] ?? '')."' agent IDE already set to '".($adapter ?? 'none')."'.");

                return self::SUCCESS;
            }

            $this->line("App '".(string) ($app['name'] ?? '')."' agent IDE set to '".($adapter ?? 'none')."'.");

            return self::SUCCESS;
        }

        $this->line(json_encode([
            'success' => [
                'data' => $data,
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
}
