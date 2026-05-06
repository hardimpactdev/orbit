<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\AgentIde\SendAgentIdeMessageRequest;
use App\Http\Gateway\Responses\AgentIde\AgentIdeMessageResponse;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Services\AgentIde\AgentIdeMessageDelivery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agent-ide:message
    {message? : Message to send}
    {--app= : App name or hostname}
    {--workspace= : Workspace name or hostname}
    {--stdin : Read message from stdin}
    {--json : Output JSON}')]
#[Description('Send a message to an active Agent IDE session')]
class AgentIdeMessageCommand extends Command
{
    public function handle(
        AgentIdeMessageDelivery $delivery,
    ): int {
        $callerRole = $this->callerRole();

        if ($callerRole === 'unknown') {
            return $this->failCommand(
                code: 'local_context_invalid',
                message: 'Local node role setting is invalid.',
                meta: [
                    'setting' => 'general.local_node_role',
                    'reason' => 'unsupported_value',
                    'caller_role' => 'unknown',
                ],
            );
        }

        $message = $this->resolveMessage();

        if ($message === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Message is required.',
                meta: ['field' => 'message'],
            );
        }

        $appSelector = $this->stringOption('app');
        $workspaceSelector = $this->stringOption('workspace');

        if ($appSelector !== null && $workspaceSelector !== null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Pass either --app or --workspace, not both.',
                meta: ['fields' => ['app', 'workspace']],
            );
        }

        if ($appSelector === null && $workspaceSelector === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Run this command from an app/workspace directory or pass --app/--workspace.',
                meta: ['field' => 'target'],
            );
        }

        if ($callerRole !== 'gateway') {
            return $this->forwardMessage($message, $appSelector, $workspaceSelector);
        }

        try {
            $data = $workspaceSelector !== null
                ? $delivery->deliverToWorkspace($workspaceSelector, $message)
                : $delivery->deliverToApp((string) $appSelector, $message);

            return $this->successCommand($data);
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'adapter_delivery_failed',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
            );
        }
    }

    private function forwardMessage(string $message, ?string $app, ?string $workspace): int
    {
        if (! $this->hasConfiguredGateway()) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to send Agent IDE messages.',
                meta: [],
            );
        }

        try {
            /** @var AgentIdeMessageResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new SendAgentIdeMessageRequest($message, $app, $workspace))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to send Agent IDE messages.',
                meta: $e->errorMeta(),
            );
        } catch (\Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to send Agent IDE messages.',
                meta: [],
            );
        }

        return $this->successCommand($dto->data);
    }

    private function hasConfiguredGateway(): bool
    {
        return LocalGatewaySettings::query()
            ->whereNotNull('gateway_url')
            ->where('gateway_url', '!=', '')
            ->exists();
    }

    private function callerRole(): string
    {
        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        if (! is_string($localRole) || $localRole === '') {
            return 'control';
        }

        if (! in_array($localRole, ['gateway', 'app', 'control'], true)) {
            return 'unknown';
        }

        return $localRole;
    }

    private function resolveMessage(): ?string
    {
        if ($this->option('stdin') === true) {
            return null;
        }

        $message = $this->argument('message');

        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        return trim($message);
    }

    /**
     * @param  array{agent_ide: array<string, mixed>}  $data
     */
    private function successCommand(array $data): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $agentIde = $data['agent_ide'];
        $target = $agentIde['target'];
        $app = (string) ($target['app'] ?? '');
        $adapter = (string) ($agentIde['adapter'] ?? '');

        $this->line("Sent message to {$app} through {$adapter}.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if (! $this->wantsJson()) {
            $this->error($message);

            return self::FAILURE;
        }

        $this->line(json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }
}
