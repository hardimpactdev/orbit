<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\AgentIdeMessageAdapter;
use App\Models\App;
use App\Models\Node;
use App\Services\AgentIde\AgentIdeAdapterRegistry;
use App\Services\AgentIde\NullAgentIdeMessageAdapter;
use App\Services\Apps\AppAgentIdeDefaults;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agent-ide:message
    {message? : Message to send}
    {--app= : App name or hostname}
    {--workspace= : Workspace name or hostname}
    {--stdin : Read message from stdin}
    {--json : Output JSON}')]
#[Description('Send a message to an active Agent IDE session')]
class AgentIdeMessageCommand extends Command
{
    public function handle(
        AppAgentIdeDefaults $appAgentIdeDefaults,
        AgentIdeAdapterRegistry $registry,
    ): int {
        $callerRole = $this->callerRole();

        if ($callerRole === 'unknown') {
            return $this->failCommand(
                code: 'local_context_invalid',
                message: 'Local node role setting is invalid.',
                meta: [
                    'setting' => 'general.local_node_role',
                    'reason' => 'unsupported_value',
                    'caller_role' => 'unknown',
                ],
            );
        }

        if ($callerRole !== 'gateway') {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to send Agent IDE messages.',
                meta: [],
            );
        }

        $message = $this->resolveMessage();

        if ($message === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Message is required.',
                meta: ['field' => 'message'],
            );
        }

        $appSelector = $this->stringOption('app');
        $workspaceSelector = $this->stringOption('workspace');

        if ($appSelector !== null && $workspaceSelector !== null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Pass either --app or --workspace, not both.',
                meta: ['fields' => ['app', 'workspace']],
            );
        }

        if ($workspaceSelector !== null) {
            return $this->failCommand(
                code: 'target_not_found',
                message: "Workspace '{$workspaceSelector}' not found or not visible.",
                meta: ['workspace' => $workspaceSelector],
            );
        }

        if ($appSelector === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Run this command from an app/workspace directory or pass --app/--workspace.',
                meta: ['field' => 'target'],
            );
        }

        $app = $this->resolveApp($appSelector);

        if (! $app instanceof App) {
            return $this->failCommand(
                code: 'target_not_found',
                message: "App '{$appSelector}' not found or not visible.",
                meta: ['app' => $appSelector],
            );
        }

        $adapter = $appAgentIdeDefaults->payloadFor($app);
        $adapterName = $adapter['effective_adapter'];

        if ($adapterName === null) {
            return $this->failCommand(
                code: 'no_effective_adapter',
                message: "No Agent IDE adapter is configured for {$app->name}.",
                meta: ['app' => $app->name, 'workspace' => null],
            );
        }

        if (! $registry->isRegisteredAdapter($adapterName)) {
            return $this->failCommand(
                code: 'no_effective_adapter',
                message: "Agent IDE adapter {$adapterName} is not registered.",
                meta: ['app' => $app->name, 'workspace' => null, 'adapter' => $adapterName],
            );
        }

        $target = [
            'app' => $app->name,
            'workspace' => null,
            'node' => (string) $app->node?->name,
        ];
        $messageAdapter = $this->messageAdapter();
        $session = $messageAdapter->activeSession($target, $adapterName);

        if ($session === null) {
            return $this->failCommand(
                code: 'no_active_session',
                message: "No active Agent IDE session found for {$app->name}.",
                meta: ['app' => $app->name, 'workspace' => null, 'adapter' => $adapterName],
            );
        }

        $messageAdapter->deliver($target, $adapterName, $session, $message);

        return $this->successCommand([
            'agent_ide' => [
                'adapter' => $adapterName,
                'source' => $adapter['source'],
                'target' => $target,
                'session' => $session,
                'delivery' => [
                    'status' => 'sent',
                    'message_bytes' => strlen($message),
                    'input' => 'argument',
                ],
            ],
        ]);
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

    private function resolveMessage(): ?string
    {
        if ($this->option('stdin') === true) {
            return null;
        }

        $message = $this->argument('message');

        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        return trim($message);
    }

    private function resolveApp(string $selector): ?App
    {
        return App::query()
            ->with('node')
            ->get()
            ->first(fn (App $app): bool => $app->name === $selector
                || $app->domain === $selector
                || $app->url() === "https://{$selector}");
    }

    private function messageAdapter(): AgentIdeMessageAdapter
    {
        return app()->bound(AgentIdeMessageAdapter::class)
            ? app(AgentIdeMessageAdapter::class)
            : new NullAgentIdeMessageAdapter;
    }

    /**
     * @param  array{agent_ide: array<string, mixed>}  $data
     */
    private function successCommand(array $data): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $agentIde = $data['agent_ide'];
        $target = $agentIde['target'];
        $app = (string) ($target['app'] ?? '');
        $adapter = (string) ($agentIde['adapter'] ?? '');

        $this->line("Sent message to {$app} through {$adapter}.");

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
}
