<?php

declare(strict_types=1);

namespace App\Commands\WebSocket;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppAttachmentResponse;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppResponse;

abstract class WebSocketAppCommand extends GatewayCommand
{
    public const string NAME_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    protected function instanceSelector(): ?string
    {
        $selector = $this->stringOption('instance');

        if ($selector === null) {
            $this->renderGatewayFailure('websocket.instance_required', 'Instance ID or Route domain is required.');
        }

        return $selector;
    }

    protected function appName(string $argument): ?string
    {
        $name = $this->stringArgument($argument, 'WebSocket app name', 'websocket.name_required');

        if ($name === null) {
            return null;
        }

        if (strlen($name) > 63 || preg_match(self::NAME_PATTERN, $name) !== 1) {
            $this->renderGatewayFailure('websocket.name_invalid', 'WebSocket app name is invalid.');

            return null;
        }

        return $name;
    }

    protected function renderApp(WebSocketAppResponse $app, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($app->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($message, [
            'ID' => $app->id,
            'Name' => $app->name,
            'App ID' => $app->appId,
            'Key' => $app->key,
            'Instance IDs' => $app->instanceIds === [] ? null : implode(', ', $app->instanceIds),
            'Request ID' => $app->requestId,
        ]));

        return self::SUCCESS;
    }

    protected function renderAttachment(WebSocketAppAttachmentResponse $attachment, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($attachment->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($message, [
            'Instance ID' => $attachment->instanceId,
            'Name' => $attachment->name,
            'Keys' => implode(', ', $attachment->keys),
            'Operation' => $attachment->operation,
            'Changed' => $attachment->changed ? 'true' : 'false',
            'Stored keys' => $attachment->keyCount,
            'Workload file' => 'unchanged',
            'Request ID' => $attachment->requestId,
        ]));

        return self::SUCCESS;
    }
}
