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
        private WebSocketRegistry $registry = new WebSocketRegistry,
    ) {}

    public function ensure(Node $node): WebSocketServerCredentials
    {
        $scope = $this->scope($node);
        $laravelAppKey = $this->settings->get($scope, WebSocketFootprint::SettingKeyAppKeyLaravel)
            ?? $this->generateLaravelAppKey($scope);

        $runtime = $this->registry->write(function () use ($node): WebSocketRuntime {
            if (! WebSocketApp::query()->where('name', WebSocketApp::GatewayName)->exists()) {
                $this->apps->issue(WebSocketApp::GatewayName);
            }
            $runtime = WebSocketRuntime::query()->firstOrCreate(['node_id' => $node->id, 'slot' => 'default'], [
                'id' => (string) Str::uuid(), 'registry_token' => Str::random(64), 'notification_token' => Str::random(64),
            ]);
            if (! $runtime->required) {
                $runtime->update(['required' => true, 'registry_token' => Str::random(64), 'notification_token' => Str::random(64),
                    'boot_id' => null, 'epoch' => null, 'applied_revision' => null, 'reported_at' => null, 'state' => 'starting', 'error' => null]);
            }

            return $runtime;
        });

        return new WebSocketServerCredentials($laravelAppKey, $runtime->id, $runtime->registry_token, $runtime->notification_token);
    }

    public function current(): ?WebSocketCredentials
    {
        $assignments = NodeRole::query()
            ->where('role', RoleName::WebSocket->value)
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

        // A role's convergence status does not describe its running process. Keep a confirmed Gateway
        // app through unrelated DNS or registry publication failures, including an older applied revision.
        $version = app(WebSocketRegistry::class)->version();
        $confirmed = WebSocketRuntime::query()
            ->where('required', true)
            ->where('state', 'serving')
            ->whereNotNull('boot_id')
            ->where('epoch', $version['epoch'])
            ->where('applied_revision', '>=', 1)
            ->pluck('node_id')
            ->all();
        $addresses = [];
        foreach ($this->dnsTarget->servingNodes($node) as $serving) {
            if (in_array($serving->id, $confirmed, true) && $this->dnsTarget->isServing($serving->id)
                && $serving->status === LifecycleStatus::Active && is_string($serving->wireguard_ip) && $serving->wireguard_ip !== '') {
                $addresses[] = $serving->wireguard_ip;
            }
        }
        if ($addresses === []) {
            return null;
        }

        return new WebSocketCredentials(
            $app->app_id,
            $app->key,
            $app->secret,
            $addresses[0],
            $addresses,
        );
    }

    public function purge(Node $node, bool $runtimeStopped = false): void
    {
        $this->settings->delete($this->scope($node), WebSocketFootprint::SettingKeyAppKeyLaravel);
        if ($runtimeStopped) {
            $this->registry->write(fn (): int => WebSocketRuntime::query()->where('node_id', $node->id)->where('slot', 'default')->update(['required' => false]));
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
