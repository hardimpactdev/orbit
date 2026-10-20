<?php

declare(strict_types=1);

namespace App\Actions\WebSocket;

use App\Data\WebSocket\WebSocketAppAttachmentData;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketAppEnvProjection;
use App\Models\Instance;
use App\Models\WebSocketApp;
use App\Models\WebSocketAppTarget;
use Illuminate\Support\Facades\DB;

final readonly class DetachWebSocketAppAction
{
    public function __construct(
        private InstanceEnvironmentOperationLock $operations,
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentStore $store,
    ) {}

    public function execute(Instance $instance, WebSocketApp $app): WebSocketAppAttachmentData
    {
        return $this->operations->run([$instance->id], function () use ($instance, $app): WebSocketAppAttachmentData {
            $context = $this->contexts->resolve($instance->refresh(), requireActiveNode: false);

            return DB::transaction(function () use ($instance, $app, $context): WebSocketAppAttachmentData {
                $target = WebSocketAppTarget::query()
                    ->where('instance_id', $instance->id)
                    ->where('websocket_app_id', $app->id)
                    ->lockForUpdate()
                    ->first();

                if (! $target instanceof WebSocketAppTarget) {
                    throw new ResourceOperationException(
                        errorCode: 'websocket.attachment_missing',
                        message: "WebSocket app [{$app->name}] is not on this Instance.",
                        status: 404,
                    );
                }

                $target->delete();
                $forgotten = $this->store->forget($context, WebSocketAppEnvProjection::Keys, 'detach');

                return new WebSocketAppAttachmentData(
                    instanceId: $instance->id,
                    name: $app->name,
                    keys: WebSocketAppEnvProjection::Keys,
                    operation: 'detach',
                    changed: $forgotten->changed,
                    keyCount: $forgotten->keyCount,
                );
            });
        });
    }
}
