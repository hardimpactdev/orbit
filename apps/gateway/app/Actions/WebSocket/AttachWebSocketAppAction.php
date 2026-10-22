<?php

declare(strict_types=1);

namespace App\Actions\WebSocket;

use App\Data\WebSocket\WebSocketAppAttachmentData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketAppEnvProjection;
use App\Domain\WebSocket\WebSocketRegistry;
use App\Domain\WebSocket\WebSocketRoutePublisher;
use App\Domain\WebSocket\WebSocketRouteSelection;
use App\Models\Instance;
use App\Models\WebSocketApp;
use App\Models\WebSocketAppTarget;

/** Writes one app's credentials into an Instance's stored environment. It replaces another app's. */
final readonly class AttachWebSocketAppAction
{
    public function __construct(
        private InstanceEnvironmentOperationLock $operations,
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentStore $store,
        private WebSocketAppEnvProjection $projection,
        private DevelopmentProjectionOperationLock $projections,
        private WebSocketRouteSelection $routes,
        private WebSocketRoutePublisher $publisher,
        private WebSocketRegistry $registry = new WebSocketRegistry,
    ) {}

    public function execute(Instance $instance, WebSocketApp $app, ?bool $public = null): WebSocketAppAttachmentData
    {
        if ($app->isGateway()) {
            throw new ResourceOperationException(
                errorCode: 'websocket.app_reserved',
                message: "WebSocket app [{$app->name}] belongs to the Gateway. Create an app for the Instance.",
                status: 409,
            );
        }

        return $this->operations->run([$instance->id], fn (): WebSocketAppAttachmentData => $this->projections->run(function () use ($instance, $app, $public): WebSocketAppAttachmentData {
            $context = $this->contexts->resolve($instance->refresh(), requireActiveNode: false);

            $previous = WebSocketAppTarget::query()->where('instance_id', $instance->id)->first()?->route;
            $route = $this->routes->select($instance, $app, $public);
            $result = $this->registry->write(function () use ($instance, $app, $context, $route): WebSocketAppAttachmentData {
                $app->refresh();
                $target = WebSocketAppTarget::query()->updateOrCreate(
                    ['instance_id' => $instance->id],
                    ['websocket_app_id' => $app->id, 'route_id' => $route?->id],
                );
                $written = $this->store->putMany($context, $this->projection->project($app, $target), 'attach');

                return new WebSocketAppAttachmentData(
                    instanceId: $instance->id,
                    name: $app->name,
                    keys: WebSocketAppEnvProjection::Keys,
                    operation: 'attach',
                    changed: $written->changed,
                    keyCount: $written->keyCount,
                );
            });
            foreach (collect([$previous, $route])->filter()->unique('id') as $publication) {
                $this->publisher->publish($publication);
            }

            return $result;
        }));
    }
}
