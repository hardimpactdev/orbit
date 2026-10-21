<?php

declare(strict_types=1);

namespace App\Actions\WebSocket;

use App\Data\WebSocket\WebSocketAppAttachmentData;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketAppEnvProjection;
use App\Domain\WebSocket\WebSocketRegistry;
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
    ) {}

    public function execute(Instance $instance, WebSocketApp $app): WebSocketAppAttachmentData
    {
        if ($app->isGateway()) {
            throw new ResourceOperationException(
                errorCode: 'websocket.app_reserved',
                message: "WebSocket app [{$app->name}] belongs to the Gateway. Create an app for the Instance.",
                status: 409,
            );
        }

        return $this->operations->run([$instance->id], function () use ($instance, $app): WebSocketAppAttachmentData {
            $context = $this->contexts->resolve($instance->refresh(), requireActiveNode: false);

            return app(WebSocketRegistry::class)->write(function () use ($instance, $app, $context): WebSocketAppAttachmentData {
                $app->refresh();
                WebSocketAppTarget::query()->updateOrCreate(
                    ['instance_id' => $instance->id],
                    ['websocket_app_id' => $app->id],
                );
                $written = $this->store->putMany($context, $this->projection->project($app), 'attach');

                return new WebSocketAppAttachmentData(
                    instanceId: $instance->id,
                    name: $app->name,
                    keys: WebSocketAppEnvProjection::Keys,
                    operation: 'attach',
                    changed: $written->changed,
                    keyCount: $written->keyCount,
                );
            });
        });
    }
}
