<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketAppIssuer;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Domain\WebSocket\WebSocketRegistry;
use App\Domain\WebSocket\WebSocketServerCredentials;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\WebSocketApp;
use App\Models\WebSocketRuntime;
use Illuminate\Support\Str;

final readonly class NativeWebSocketCredentialManager implements WebSocketCredentialManager
{
    public function __construct(
        private SettingRepository $settings,
        private WebSocketAppIssuer $apps = new WebSocketAppIssuer,
        private WebSocketDnsTarget $dnsTarget = new WebSocketDnsTarget,
    ) {}

    public function ensure(Node $node): WebSocketServerCredentials
    {
        $scope = $this->scope($node);
        $laravelAppKey = $this->settings->get($scope, WebSocketFootprint::SettingKeyAppKeyLaravel)
            ?? $this->generateLaravelAppKey($scope);

        $runtime = app(WebSocketRegistry::class)->write(function () use ($node): WebSocketRuntime {
            if (! WebSocketApp::query()->where('name', WebSocketApp::GatewayName)->exists()) {
                $this->apps->issue(WebSocketApp::GatewayName);
            }
            $runtime = WebSocketRuntime::query()->firstOrCreate(['node_id' => $node->id, 'slot' => 'default'], [
                'id' => (string) Str::uuid(), 'registry_token' => Str::random(64), 'notification_token' => Str::random(64),
            ]);
            if (! $runtime->required) {
                $runtime->update(['required' => true, 'registry_token' => Str::random(64), 'notification_token' => Str::random(64),
                    'boot_id' => null, 'epoch' => null, 'applied_revision' => null, 'reported_at' => null, 'state' => 'starting']);
            }

            return $runtime;
        });

        return new WebSocketServerCredentials($laravelAppKey, $runtime->id, $runtime->registry_token, $runtime->notification_token);
    }

    public function current(): ?WebSocketCredentials
    {
        $assignments = NodeRole::query()
            ->where('role', RoleName::WebSocket->value)
            ->where('status', LifecycleStatus::Active->value)
            ->with('node')
            ->limit(2)
            ->get();

        if ($assignments->count() !== 1) {
            return null;
        }

        $node = $assignments->sole()->node;
        $app = WebSocketApp::query()->where('name', WebSocketApp::GatewayName)->first();

        if ($node->status !== LifecycleStatus::Active || ! $app instanceof WebSocketApp) {
            return null;
        }

        // The Gateway's own realtime client reaches `reverb.orbit` where private DNS sends every other client:
        // during a move, the old Node until the new Node's build serves the site.
        // During a move it also publishes to and listens on the old Node until that Node withdraws.
        $addresses = array_values(array_filter(
            array_map(static fn (Node $serving): ?string => $serving->wireguard_ip, $this->dnsTarget->servingNodes($node)),
            static fn (?string $address): bool => is_string($address) && $address !== '',
        ));

        return new WebSocketCredentials(
            $app->app_id,
            $app->key,
            $app->secret,
            $addresses[0] ?? null,
            $addresses,
        );
    }

    public function purge(Node $node, bool $runtimeStopped = false): void
    {
        $this->settings->delete($this->scope($node), WebSocketFootprint::SettingKeyAppKeyLaravel);
        if ($runtimeStopped) {
            app(WebSocketRegistry::class)->write(fn (): int => WebSocketRuntime::query()->where('node_id', $node->id)->where('slot', 'default')->update(['required' => false]));
        }
    }

    private function generateLaravelAppKey(SettingScope $scope): string
    {
        $value = 'base64:'.base64_encode(random_bytes(32));
        $this->settings->put($scope, WebSocketFootprint::SettingKeyAppKeyLaravel, $value, SettingValueProtection::Secret);

        return $value;
    }

    private function scope(Node $node): SettingScope
    {
        return new SettingScope(SettingScopeType::Node, $node->id);
    }
}
