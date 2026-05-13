<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\AgentIde\SendAgentIdeMessageRequest;
use App\Http\Gateway\Responses\AgentIde\AgentIdeMessageResponse;
use App\Models\LocalGatewaySettings;
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
        $callerRole = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';

        try {
            $message = $this->resolveMessage();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'validation_failed',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
                data: $e->errorData(),
            );
        }

        if ($message === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Message is required.',
                meta: [
                    'field' => 'message',
                    'reason' => 'missing_required_input',
                ],
            );
        }

        $appSelector = $this->stringOption('app');
        $workspaceSelector = $this->stringOption('workspace');
        $pathSelector = null;

        if ($appSelector !== null && $workspaceSelector !== null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Pass either --app or --workspace, not both.',
                meta: ['fields' => ['app', 'workspace']],
            );
        }

        if ($appSelector === null && $workspaceSelector === null) {
            $pathSelector = realpath((string) getcwd()) ?: (string) getcwd();
        }

        if ($callerRole !== 'gateway') {
            return $this->forwardMessage($message, $appSelector, $workspaceSelector, $pathSelector);
        }

        try {
            if ($pathSelector !== null) {
                $data = $delivery->deliverToPath($pathSelector, $message);
            } elseif ($workspaceSelector !== null) {
                $data = $delivery->deliverToWorkspace($workspaceSelector, $message);
            } else {
                $data = $delivery->deliverToApp((string) $appSelector, $message);
            }

            return $this->successCommand($data);
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'adapter_delivery_failed',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
                data: $e->errorData(),
            );
        }
    }

    private function forwardMessage(string $message, ?string $app, ?string $workspace, ?string $path): int
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
                ->send(new SendAgentIdeMessageRequest($message, $app, $workspace, $path))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to send Agent IDE messages.',
                meta: $e->errorMeta(),
                data: $e->errorData(),
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

    private function resolveMessage(): ?string
    {
        if ($this->option('stdin') === true) {
            $message = $this->argument('message');

            if (is_string($message) && trim($message) !== '') {
                throw new GatewayApiException(
                    message: 'Pass either [message] or --stdin, not both.',
                    errorCode: 'validation_failed',
                    errorMeta: [
                        'field' => 'message',
                        'reason' => 'conflicting_message_inputs',
                    ],
                );
            }

            $stream = method_exists($this->input, 'getStream') ? $this->input->getStream() : null;
            $body = is_resource($stream) ? stream_get_contents($stream) : stream_get_contents(STDIN);

            if (! is_string($body)) {
                return null;
            }

            $body = preg_replace("/\r?\n\z/", '', $body);

            if (! is_string($body) || trim($body) === '') {
                return null;
            }

            return $body;
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
        $workspace = $target['workspace'] ?? null;
        $targetLabel = is_string($workspace) && $workspace !== ''
            ? "{$app}/{$workspace}"
            : $app;
        $adapter = (string) ($agentIde['adapter'] ?? '');

        $this->line("┌ Sending Agent IDE message to {$targetLabel}");
        $this->line('● Resolved target');
        $this->line('● Resolved effective adapter');
        $this->line('● Found active session');
        $this->line('● Delivered message');
        $this->line("└ Sent Agent IDE message to {$targetLabel} through {$adapter}");
        $this->newLine();
        $this->line("Sent message to {$targetLabel} through {$adapter}.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta, array $data = []): int
    {
        if (! $this->wantsJson()) {
            $this->error($message);

            return self::FAILURE;
        }

        $error = [
            'code' => $code,
            'message' => $message,
            'meta' => empty($meta) ? (object) [] : $meta,
        ];

        if ($data !== []) {
            $error['data'] = $data;
        }

        $this->line(json_encode([
            'error' => [
                ...$error,
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
