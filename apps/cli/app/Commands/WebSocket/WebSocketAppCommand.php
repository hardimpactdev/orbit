<?php

declare(strict_types=1);

namespace App\Commands\WebSocket;

use App\Commands\GatewayCommand;
use App\Commands\Integrations\FollowsIntegrationOperations;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppAttachmentResponse;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppResponse;
use Orbit\Sdk\Responses\WebSocket\WebSocketChangeResponse;

abstract class WebSocketAppCommand extends GatewayCommand
{
    use FollowsIntegrationOperations;

    public const string NAME_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    protected function instanceSelector(): ?string
    {
        return $this->requiredStringOption('instance', 'Instance ID or Route domain', 'websocket.instance_required');
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

    protected function renderChange(WebSocketChangeResponse $change): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($change->toArray());

            return self::SUCCESS;
        }
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail(
            match ($change->operation->state) {
                'applied' => 'WebSocket change applied.',
                'superseded' => 'WebSocket change superseded by a later change.',
                default => 'WebSocket change saved; runtime application is pending.',
            }, [
                'App' => $change->app?->name, 'Operation ID' => $change->operation->id,
                'Desired revision' => $change->operation->revision, 'State' => $change->operation->state,
                'Instance rollout' => $change->instanceRolloutIds === null ? null : 'Stored configuration updated; run env:sync and reload each attached app.',
                'Next step' => $change->operation->state === 'pending' ? 'orbit websocket:sync' : null,
                'Request ID' => $change->requestId,
            ]));
        if ($change->operation->processes !== []) {
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(
                ['Process', 'Node', 'Applied revision', 'State'],
                array_map(static fn (array $process): array => [$process['id'], $process['node_id'], $process['applied_revision'] ?? '—', $process['state']], $change->operation->processes),
                'No process reports.',
            ));
        }

        return self::SUCCESS;
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
