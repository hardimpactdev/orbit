<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\WebSocket\WebSocketAppCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\WebSocket\RemoveInstanceWebSocketAppRequest;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppAttachmentResponse;

final class RemoveInstanceWebSocketCommand extends WebSocketAppCommand
{
    #[\Override]
    protected $signature = 'instance:websocket:remove
        {app : WebSocket app name}
        {--instance= : Positive Instance ID or exact Route domain}
        {--force : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove a Reverb app from an Instance and clear its stored environment keys.';

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

        if (! $this->confirmAction(
            "Remove WebSocket app [{$name}] from Instance [{$instance}] and clear its stored BROADCAST_CONNECTION and REVERB_* keys? Workload .env stays unchanged.",
            'WebSocket app removal cancelled.',
            option: 'force',
            requiredCode: 'websocket.confirmation_required',
            requiredMessage: 'Use --force to confirm WebSocket app removal from the Instance.',
        )) {
            return self::FAILURE;
        }

        $attachment = $this->sendWithProgress(
            $connector,
            new RemoveInstanceWebSocketAppRequest($instance, $name),
            WebSocketAppAttachmentResponse::class,
            ['Remove WebSocket app', 'Removing WebSocket app', 'Removed WebSocket app'],
        );

        if (! $attachment instanceof WebSocketAppAttachmentResponse) {
            return self::FAILURE;
        }

        return $this->renderAttachment(
            $attachment,
            "WebSocket app [{$attachment->name}] removed from Instance [{$attachment->instanceId}].",
        );
    }
}
