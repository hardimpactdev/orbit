<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\WebSocket\WebSocketAppCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\WebSocket\AddInstanceWebSocketAppRequest;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppAttachmentResponse;

final class AddInstanceWebSocketCommand extends WebSocketAppCommand
{
    #[\Override]
    protected $signature = 'instance:websocket:add
        {app : WebSocket app name}
        {--instance= : Positive Instance ID or exact Route domain}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Add a Reverb app on an Instance and write its stored environment keys. It replaces the app the Instance had.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $name = $this->appName('app');
        $instance = $this->instanceSelector();

        if ($instance === null || $name === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $attachment = $this->sendWithProgress(
            $connector,
            new AddInstanceWebSocketAppRequest($instance, $name),
            WebSocketAppAttachmentResponse::class,
            ['Add WebSocket app', 'Adding WebSocket app', 'Added WebSocket app'],
        );

        if (! $attachment instanceof WebSocketAppAttachmentResponse) {
            return self::FAILURE;
        }

        return $this->renderAttachment(
            $attachment,
            "WebSocket app [{$attachment->name}] added to Instance [{$attachment->instanceId}].",
        );
    }
}
