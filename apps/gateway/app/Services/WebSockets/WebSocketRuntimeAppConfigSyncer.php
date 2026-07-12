<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeStatus;
use App\Models\AppWebSocketBinding;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

class WebSocketRuntimeAppConfigSyncer
{
    public function __construct(
        private readonly NodeRoleAssignments $nodeRoleAssignments,
        private readonly WebSocketRuntimeContainerRenderer $runtimeContainerRenderer,
        private readonly ?RunsInternalCommands $localExecutor = null,
    ) {}

    public function sync(): void
    {
        $content = $this->configFileContent($this->enabledBindings());

        foreach ($this->webSocketNodes() as $node) {
            $result = $this->localExecutor()->runInternal(
                node: $node,
                commandName: 'internal:websocket-runtime',
                arguments: ['app-config:sync'],
                transportOptions: [
                    'throw' => true,
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => 'websocket-runtime.app-config:sync',
                    ],
                    'input' => json_encode([
                        'content' => $content,
                        'container' => $this->runtimeContainerRenderer->containerName($node),
                    ], JSON_THROW_ON_ERROR),
                    'redact_stdout' => true,
                    'redact_stderr' => true,
                ],
            );

            if (! $result->successful()) {
                throw new RuntimeException("Websocket runtime app config sync failed for node [{$node->name}].");
            }
        }
    }

    /**
     * @return Collection<int, AppWebSocketBinding>
     */
    private function enabledBindings(): Collection
    {
        return AppWebSocketBinding::query()
            ->with('app')
            ->where('enabled', true)
            ->orderBy('reverb_app_id')
            ->get();
    }

    /**
     * @return list<Node>
     */
    private function webSocketNodes(): array
    {
        /** @var list<Node> $nodes */
        $nodes = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $this->nodeRoleAssignments->activeNodeIdsForRole(NodeRoleName::WebSocket->value))
            ->orderBy('name')
            ->get()
            ->all();

        return $nodes;
    }

    /**
     * @param  Collection<int, AppWebSocketBinding>  $bindings
     */
    private function configFileContent(Collection $bindings): string
    {
        $apps = $bindings
            ->map(fn (AppWebSocketBinding $binding): array => [
                'key' => $binding->reverb_app_key,
                'secret' => $binding->reverb_app_secret,
                'app_id' => $binding->reverb_app_id,
                'options' => [
                    'host' => WebSocketRouteRegistrar::ServiceDomain,
                    'port' => 443,
                    'scheme' => 'https',
                    'useTLS' => true,
                ],
                'allowed_origins' => $this->allowedOrigins($binding),
                'ping_interval' => 60,
                'activity_timeout' => 30,
                'max_message_size' => 10_000,
            ])
            ->values()
            ->all();

        return "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($apps, true).";\n";
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(AppWebSocketBinding $binding): array
    {
        $origins = $binding->allowed_origins;

        if ($origins === []) {
            return ['*'];
        }

        $hosts = [];

        foreach ($origins as $origin) {
            if (! is_string($origin)) {
                continue;
            }

            $origin = trim($origin);

            if ($origin === '') {
                continue;
            }

            if ($origin === '*') {
                return ['*'];
            }

            $host = parse_url($origin, PHP_URL_HOST);
            $host = is_string($host) && $host !== '' ? $host : $origin;
            $host = strtolower(trim($host));

            if ($host !== '' && ! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts === [] ? ['*'] : $hosts;
    }

    private function localExecutor(): RunsInternalCommands
    {
        return $this->localExecutor ?? app(RunsInternalCommands::class);
    }
}
