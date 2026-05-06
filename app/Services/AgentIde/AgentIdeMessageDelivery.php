<?php

declare(strict_types=1);

namespace App\Services\AgentIde;

use App\Contracts\AgentIdeMessageAdapter;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Services\Apps\AppAgentIdeDefaults;

final readonly class AgentIdeMessageDelivery
{
    public function __construct(
        private AppAgentIdeDefaults $appAgentIdeDefaults,
        private AgentIdeAdapterRegistry $registry,
    ) {}

    /**
     * @return array{agent_ide: array<string, mixed>}
     */
    public function deliverToApp(string $selector, string $message): array
    {
        $app = $this->resolveApp($selector);

        if (! $app instanceof App) {
            throw new GatewayApiException(
                message: "App '{$selector}' not found or not visible.",
                errorCode: 'target_not_found',
                errorMeta: ['app' => $selector],
            );
        }

        $adapter = $this->appAgentIdeDefaults->payloadFor($app);
        $adapterName = $adapter['effective_adapter'];

        if ($adapterName === null) {
            throw new GatewayApiException(
                message: "No Agent IDE adapter is configured for {$app->name}.",
                errorCode: 'no_effective_adapter',
                errorMeta: ['app' => $app->name, 'workspace' => null],
            );
        }

        if (! $this->registry->isRegisteredAdapter($adapterName)) {
            throw new GatewayApiException(
                message: "Agent IDE adapter {$adapterName} is not registered.",
                errorCode: 'no_effective_adapter',
                errorMeta: ['app' => $app->name, 'workspace' => null, 'adapter' => $adapterName],
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
            throw new GatewayApiException(
                message: "No active Agent IDE session found for {$app->name}.",
                errorCode: 'no_active_session',
                errorMeta: ['app' => $app->name, 'workspace' => null, 'adapter' => $adapterName],
            );
        }

        $messageAdapter->deliver($target, $adapterName, $session, $message);

        return [
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
        ];
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
}
