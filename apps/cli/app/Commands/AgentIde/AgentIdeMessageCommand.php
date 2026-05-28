<?php

declare(strict_types=1);

namespace App\Commands\AgentIde;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class AgentIdeMessageCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'agent-ide:message
        {message? : Message to send}
        {--app= : App name or hostname}
        {--workspace= : Workspace name or hostname}
        {--stdin : Read message from stdin}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Send a message to an active Agent IDE session.';

    public function handle(): int
    {
        if ($this->hasConflictingMessageInputs()) {
            return $this->failValidation(
                field: 'message',
                message: 'Pass either [message] or --stdin, not both.',
                reason: 'conflicting_message_inputs',
            );
        }

        $message = $this->resolveMessage();

        if ($message === null && $this->isInteractiveInput()) {
            $message = $this->promptMessage();
        }

        if ($message === null) {
            return $this->failValidation(
                field: 'message',
                message: 'Message is required.',
                reason: 'missing_required_input',
            );
        }

        $app = $this->stringOption('app');
        $workspace = $this->stringOption('workspace');

        if ($app !== null && $workspace !== null) {
            return $this->failValidation(
                field: 'target',
                message: 'Pass either --app or --workspace, not both.',
                reason: 'conflicting_target_options',
            );
        }

        $payload = $this->payload($message, $app, $workspace);

        if ($payload === null) {
            return $this->failValidation(
                field: 'target',
                message: 'Run this command from an app/workspace directory or pass --app/--workspace.',
                reason: 'missing_required_input',
            );
        }

        try {
            $response = $this->gatewayPost('/api/agent-ide/message', $payload);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderAgentIdeSuccess($response);
    }

    private function hasConflictingMessageInputs(): bool
    {
        return $this->option('stdin') === true && $this->stringArgument('message') !== null;
    }

    private function resolveMessage(): ?string
    {
        if ($this->option('stdin') === true) {
            return $this->stdinMessage();
        }

        return $this->stringArgument('message');
    }

    private function stdinMessage(): ?string
    {
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

    private function promptMessage(): ?string
    {
        $message = $this->ask('Message');

        return is_string($message) && trim($message) !== '' ? trim($message) : null;
    }

    /**
     * @return array<string, string>|null
     */
    private function payload(string $message, ?string $app, ?string $workspace): ?array
    {
        if ($app !== null) {
            return [
                'message' => $message,
                'app' => $app,
            ];
        }

        if ($workspace !== null) {
            return [
                'message' => $message,
                'workspace' => $workspace,
            ];
        }

        $path = $this->hostCwd();

        if ($path === null) {
            return null;
        }

        return [
            'message' => $message,
            'path' => $path,
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function renderAgentIdeSuccess(array $response): int
    {
        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $data = $this->successData($response);
        $agentIde = is_array($data['agent_ide'] ?? null) ? $data['agent_ide'] : [];
        $target = is_array($agentIde['target'] ?? null) ? $agentIde['target'] : [];
        $app = is_string($target['app'] ?? null) && $target['app'] !== ''
            ? $target['app']
            : 'target';
        $workspace = is_string($target['workspace'] ?? null) && $target['workspace'] !== ''
            ? $target['workspace']
            : null;
        $targetLabel = $workspace !== null ? "{$app}/{$workspace}" : $app;
        $adapter = is_string($agentIde['adapter'] ?? null) && $agentIde['adapter'] !== ''
            ? $agentIde['adapter']
            : 'adapter';

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
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $success = $response['success'] ?? null;

        if (is_array($success) && is_array($success['data'] ?? null)) {
            return $success['data'];
        }

        return $response;
    }

    private function failValidation(string $field, string $message, string $reason): int
    {
        return $this->renderFailure('validation_failed', $message, [
            'field' => $field,
            'reason' => $reason,
        ]);
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }
}
