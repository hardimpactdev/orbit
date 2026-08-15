<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Data\Apps\LaravelCloudInstanceDriverConfigData;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\AppWebSocketBinding;
use App\Models\Instance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final readonly class WebSocketBindingService
{
    public function __construct(
        private WebSocketRouteRegistrar $routes,
        private WebSocketRuntimeAppConfigSyncer $runtimeAppConfigSyncer,
    ) {}

    /**
     * @param  array<int, mixed>  $publicHosts
     */
    public function enable(Instance $instance, array $publicHosts): AppWebSocketBinding
    {
        $binding = DB::transaction(function () use ($instance, $publicHosts): AppWebSocketBinding {
            $this->routes->syncServiceRoute();

            $instance->loadMissing('app');
            $binding = $this->existingBinding($instance);
            $attributes = [
                'enabled' => true,
                'allowed_origins' => $this->allowedOrigins($instance),
                'public_hosts' => $this->normalizePublicHosts($publicHosts),
            ];

            if ($binding instanceof AppWebSocketBinding) {
                $binding->fill($attributes);
                $binding->save();
            } else {
                $binding = AppWebSocketBinding::query()->create([
                    'instance_id' => $instance->id,
                    'reverb_app_id' => "{$instance->app->name}.{$instance->name}",
                    'reverb_app_key' => Str::random(32),
                    'reverb_app_secret' => Str::random(48),
                    ...$attributes,
                ]);
            }

            $binding = $binding->refresh();
            $this->routes->syncPublicHosts($binding);

            return $binding->refresh();
        });

        $this->runtimeAppConfigSyncer->sync();

        return $binding->refresh();
    }

    public function credentials(Instance $instance): WebSocketCredentials
    {
        $binding = $this->enabledBinding($instance);

        return WebSocketCredentials::fromBinding($binding);
    }

    public function disable(Instance $instance): AppWebSocketBinding
    {
        $binding = DB::transaction(function () use ($instance): AppWebSocketBinding {
            $binding = $this->binding($instance);

            $binding->fill([
                'enabled' => false,
                'public_hosts' => [],
            ]);
            $binding->save();

            $binding = $binding->refresh();
            $this->routes->syncPublicHosts($binding);

            return $binding->refresh();
        });

        $this->runtimeAppConfigSyncer->sync();

        return $binding->refresh();
    }

    private function existingBinding(Instance $instance): ?AppWebSocketBinding
    {
        $binding = AppWebSocketBinding::query()
            ->where('instance_id', $instance->id)
            ->first();

        return $binding instanceof AppWebSocketBinding ? $binding : null;
    }

    private function binding(Instance $instance): AppWebSocketBinding
    {
        $binding = $this->existingBinding($instance);

        if (! $binding instanceof AppWebSocketBinding) {
            throw new RuntimeException("Instance '{$instance->name}' does not have a websocket binding.");
        }

        return $binding;
    }

    private function enabledBinding(Instance $instance): AppWebSocketBinding
    {
        $binding = $this->binding($instance);

        if (! $binding->enabled) {
            throw new RuntimeException("Instance '{$instance->name}' does not have an enabled websocket binding.");
        }

        return $binding;
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(Instance $instance): array
    {
        $config = $instance->driver_config;
        $domain = match (true) {
            $config instanceof OrbitInstanceDriverConfigData => trim((string) $config->domain),
            $config instanceof LaravelCloudInstanceDriverConfigData => trim((string) $config->domain),
            default => '',
        };

        if ($domain === '') {
            return [];
        }

        return ["https://{$domain}"];
    }

    /**
     * @param  array<int, mixed>  $publicHosts
     * @return list<string>
     */
    private function normalizePublicHosts(array $publicHosts): array
    {
        $hosts = [];

        foreach ($publicHosts as $publicHost) {
            if (! is_string($publicHost)) {
                throw new InvalidArgumentException('WebSocket public hosts must be strings.');
            }

            $host = Str::lower(trim($publicHost));

            if ($host === '') {
                continue;
            }

            if (str_contains($host, '://')) {
                throw new InvalidArgumentException('WebSocket public hosts must be hostnames, not URLs.');
            }

            if (! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }
}
