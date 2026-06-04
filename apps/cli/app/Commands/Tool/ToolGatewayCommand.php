<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\Concerns\StreamsGatewayProgress;
use App\Commands\GatewayCommand;
use App\Exceptions\OrbitConfigStoreException;
use App\Services\OrbitConfigStore;
use Orbit\Core\Progress\ProgressEventType;

abstract class ToolGatewayCommand extends GatewayCommand
{
    use ResolvesHostContext;
    use StreamsGatewayProgress;

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    protected function failValidation(string $field, string $message, array $extraMeta = []): int
    {
        return $this->renderFailure('validation_failed', $message, array_merge(['field' => $field], $extraMeta));
    }

    protected function requireToolArgument(string $message = 'A tool name is required.'): string|int
    {
        $tool = $this->stringArgument('tool');

        if ($tool === null) {
            if (! $this->wantsJson() && $this->input->isInteractive()) {
                $answer = $this->ask('Tool name');

                if (is_string($answer) && trim($answer) !== '') {
                    return trim($answer);
                }
            }

            return $this->failValidation('tool', $message);
        }

        return $tool;
    }

    /**
     * @return array<string, string>|int
     */
    protected function toolTargetPayload(bool $requireTarget = false): array|int
    {
        $app = $this->stringOption('app');
        $node = $this->stringOption('node');

        if ($node === null && $app === null) {
            try {
                $node = app(OrbitConfigStore::class)->defaultNode();
            } catch (OrbitConfigStoreException $exception) {
                return $this->renderFailure($exception->orbitCode, $exception->getMessage());
            }
        }

        $payload = $this->filledQuery([
            'app' => $app,
            'node' => $node,
        ]);

        if ($requireTarget && $payload === []) {
            if (! $this->wantsJson() && $this->input->isInteractive()) {
                $answer = $this->ask('Target node');

                if (is_string($answer) && trim($answer) !== '') {
                    return ['node' => trim($answer)];
                }
            }

            return $this->renderFailure(
                'validation_failed',
                'A node or app target is required.',
                ['fields' => ['target']],
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function streamToolAction(string $tool, string $action, array $payload): int
    {
        return $this->streamProgress(
            '/api/tools/'.rawurlencode($tool).'/'.$action,
            $payload,
            fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function streamToolBulkAction(string $action, array $payload): int
    {
        return $this->streamProgress(
            '/api/tools/'.$action,
            $payload,
            fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload),
        );
    }
}
