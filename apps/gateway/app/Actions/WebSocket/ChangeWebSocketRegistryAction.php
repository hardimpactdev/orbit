<?php

declare(strict_types=1);

namespace App\Actions\WebSocket;

use App\Data\WebSocket\WebSocketAppData;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketAppEnvProjection;
use App\Domain\WebSocket\WebSocketAppIssuer;
use App\Domain\WebSocket\WebSocketRegistry;
use App\Models\Instance;
use App\Models\WebSocketApp;
use App\Models\WebSocketAppTarget;
use App\Models\WebSocketRegistryOperation;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ChangeWebSocketRegistryAction
{
    public function __construct(
        private WebSocketRegistry $registry,
        private WebSocketAppIssuer $issuer,
        private InstanceEnvironmentOperationLock $environments,
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentStore $store,
        private WebSocketAppEnvProjection $projection,
    ) {}

    public function execute(string $kind, ?string $name, int $actorId, string $idempotencyKey): WebSocketRegistryOperation
    {
        $targetIds = $kind === 'rotate' ? $this->targets($name) : [];

        return $this->environments->run($targetIds, fn (): WebSocketRegistryOperation => $this->registry->write(function () use ($kind, $name, $actorId, $idempotencyKey, $targetIds): WebSocketRegistryOperation {
            $previous = WebSocketRegistryOperation::query()->where('actor_node_id', $actorId)->where('idempotency_key', $idempotencyKey)->first();
            if ($previous instanceof WebSocketRegistryOperation) {
                if ($previous->kind !== $kind || $previous->app_name !== $name) {
                    throw new ResourceOperationException('websocket.idempotency_conflict', 'This operation key belongs to another change.', 409);
                }

                return $previous;
            }

            $result = [];
            $generation = null;
            if ($kind === 'create') {
                if ($name === null || $name === WebSocketApp::GatewayName || WebSocketApp::query()->where('name', $name)->exists()) {
                    throw ValidationException::withMessages(['name' => ['The app name is reserved or taken.']]);
                }
                $app = $this->issuer->issue($name);
                $result = WebSocketAppData::fromModel($app)->toArray();
            } elseif (in_array($kind, ['destroy', 'rotate'], true)) {
                $app = WebSocketApp::query()->where('name', $name)->firstOrFail();
                if ($app->isGateway()) {
                    throw new ResourceOperationException('websocket.app_reserved', 'The gateway app is reserved.', 409);
                }
                $result = WebSocketAppData::fromModel($app)->toArray();
                if ($kind === 'destroy') {
                    if ($app->targets()->exists()) {
                        throw new ResourceOperationException('websocket.app_in_use', 'Remove the app from each Instance first.', 409);
                    }
                    $app->delete();
                } else {
                    if ($this->targets($name) !== $targetIds) {
                        throw new ResourceOperationException('websocket.attachments_changed', 'App attachments changed. Retry rotation.', 409);
                    }
                    $app->secret = Str::random(40);
                    $app->credential_generation++;
                    $generation = $app->credential_generation;
                    $app->save();
                    foreach ($targetIds as $id) {
                        $instance = Instance::query()->findOrFail($id);
                        $context = $this->contexts->resolve($instance, requireActiveNode: false);
                        $this->store->putMany($context, $this->projection->project($app), 'websocket-rotate');
                    }
                    $result['instance_rollout'] = ['state' => 'configuration_stored', 'instance_ids' => $targetIds, 'requires_env_sync' => true, 'requires_reload' => true];
                }
            } elseif ($kind !== 'sync') {
                throw new ResourceOperationException('websocket.change_invalid', 'Unsupported registry change.', 422);
            }

            $version = $kind === 'sync' || $kind === 'create' ? $this->registry->version() : $this->registry->advance();

            return WebSocketRegistryOperation::query()->create([
                'id' => (string) Str::uuid(), 'actor_node_id' => $actorId, 'idempotency_key' => $idempotencyKey,
                'kind' => $kind, 'app_name' => $name, 'credential_generation' => $generation, ...$version, 'result' => $result,
            ]);
        }));
    }

    /** @return list<int> */
    private function targets(?string $name): array
    {
        $app = WebSocketApp::query()->where('name', $name)->first();

        return $app instanceof WebSocketApp ? array_values($app->targets()->orderBy('instance_id')->get()->map(static fn (WebSocketAppTarget $target): int => $target->instance_id)->all()) : [];
    }
}
